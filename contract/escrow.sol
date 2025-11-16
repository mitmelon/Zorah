// SPDX-License-Identifier: MIT
pragma solidity ^0.8.30;

import "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import "@openzeppelin/contracts/token/ERC20/utils/SafeERC20.sol";
import "@openzeppelin/contracts/access/AccessControl.sol";
import "@openzeppelin/contracts/security/Pausable.sol";
import "@openzeppelin/contracts/security/ReentrancyGuard.sol";

interface IERC20Permit {
    function permit(address owner, address spender, uint256 value, uint256 deadline, uint8 v, bytes32 r, bytes32 s) external;
}

interface IERC3009 {
    function transferWithAuthorization(address from, address to, uint256 value, uint256 validAfter, uint256 validBefore, bytes32 nonce, uint8 v, bytes32 r, bytes32 s) external;
}

/**
 * @title Zorah - Secure Escrow and Payment Protocol
 * @notice Handles escrow transactions, disputes, and automated vaults with internal accounting
 * @dev Version 2.0.0
 */
contract Zorah is AccessControl, ReentrancyGuard, Pausable {
    using SafeERC20 for IERC20;

    bytes32 public constant RELAYER_ROLE = keccak256("RELAYER_ROLE");
    bytes32 public constant ADMIN_ROLE = keccak256("ADMIN_ROLE");
    bytes32 public constant JUROR_ROLE = keccak256("JUROR_ROLE");
    bytes32 public constant PREMIUM_USER_ROLE = keccak256("PREMIUM_USER_ROLE");

    uint256 public constant BASIS_POINTS = 10000;
    uint256 public constant MAX_JURORS = 100;
    uint256 public constant DISPUTE_DURATION = 7 days;
    uint256 public constant MAX_ESCROW_DURATION = 30 days;
    uint256 public constant EXTERNAL_TRANSFER_FEE_CAP = 10 * 10**6;

    uint256 public escrowFeePercentage = 50;
    uint256 public maxEscrowFee = 10 * 10**6;
    uint256 public disputePenaltyPercentage = 10;
    uint256 public quorumPercentage = 90;
    uint256 public lpRewardFeePercentage = 50;

    uint256 private _escrowIdCounter;
    uint256 private _disputeIdCounter;
    
    address public adminWallet;

    struct Escrow {
        address seller;
        address buyer;
        IERC20 token;
        uint256 amount;
        uint256 expiryTime;
        bool isCompleted;
        bool isCancelled;
        uint256 disputeId;
        bool isAutomated;
    }

    struct Dispute {
        uint256 escrowId;
        uint256 disputedAmount;
        uint256 totalVotes;
        uint256 positiveVotes;
        address[] voters;
        mapping(address => bool) hasVoted;
        mapping(address => bool) voteChoice;
        bool isResolved;
        bool sellerWins;
        uint256 deadline;
    }

    mapping(uint256 => Escrow) public escrows;
    mapping(address => mapping(IERC20 => uint256)) private automatedVaults;
    mapping(address => bool) public isBlacklisted;
    mapping(address => mapping(IERC20 => uint256)) public userBalances;
    mapping(uint256 => Dispute) public disputes;
    mapping(uint256 => mapping(address => bool)) public disputeRewarded;
    mapping(address => bool) public isSupportedToken;
    mapping(uint256 => mapping(address => uint256)) public pendingRewards;
    mapping(IERC20 => uint256) private _totalTokenBalances;

    mapping(bytes32 => address[]) private _roleMembers;
    mapping(bytes32 => mapping(address => uint256)) private _roleMemberIndex;

    bytes32 public constant EIP712_DOMAIN_TYPEHASH = keccak256("EIP712Domain(string name,string version,uint256 chainId,address verifyingContract)");
    bytes32 public constant EIP712_DEPOSIT_TYPEHASH = keccak256("Deposit(address user,address token,uint256 amount,uint256 nonce)");
    bytes32 public constant EIP712_WITHDRAW_TYPEHASH = keccak256("Withdraw(address user,address token,uint256 amount,uint256 nonce)");
    bytes32 public constant EIP712_JURORVOTE_TYPEHASH = keccak256("JurorVote(address juror,uint256 disputeId,bool voteForSeller,uint256 nonce)");
    bytes32 public immutable EIP712_DOMAIN_SEPARATOR;
    mapping(address => uint256) private _depositNonces;
    mapping(address => uint256) private _withdrawNonces;
    mapping(address => uint256) private _jurorVoteNonces;

    event Deposited(address indexed user, IERC20 indexed token, uint256 amount);
    event WithdrawnToWallet(address indexed user, IERC20 indexed token, uint256 amount);
    event EscrowCreated(uint256 indexed escrowId, address indexed seller, address indexed buyer, uint256 amount, bool isAutomated);
    event EscrowCompleted(uint256 indexed escrowId, uint256 fee);
    event EscrowCancelled(uint256 indexed escrowId);
    event InternalTransferred(address indexed from, address indexed to, IERC20 indexed token, uint256 amount);
    event DisputeCreated(uint256 indexed disputeId, uint256 indexed escrowId, uint256 deadline);
    event DisputeVoted(uint256 indexed disputeId, address indexed voter, bool vote);
    event DisputeResolved(uint256 indexed disputeId, bool sellerWins, uint256 rewardPool, bytes32 fundDisbursement);
    event DisputeTieAdminHold(uint256 indexed disputeId, IERC20 indexed token, uint256 amount);
    event RewardClaimed(uint256 indexed disputeId, address indexed juror, uint256 amount);
    event InsufficientContractFunds(IERC20 indexed token, uint256 required, uint256 available);
    event FundsWithdrawnForYield(IERC20 indexed token, uint256 amount, address indexed to);
    event LpRewardFeePercentageUpdated(uint256 oldFee, uint256 newFee);
    event AdminWithdrawal(address indexed token, uint256 amount);

    modifier notBlacklisted(address user) {
        require(!isBlacklisted[user], "Blacklisted");
        _;
    }

    modifier validToken(IERC20 token) {
        require(isSupportedToken[address(token)], "Invalid token");
        _;
    }

    constructor(address _admin, address _relayer) {
        require(_admin != address(0) && _relayer != address(0), "Invalid");
        adminWallet = _admin;
        _grantRole(DEFAULT_ADMIN_ROLE, _admin);
        _grantRole(ADMIN_ROLE, _admin);
        _grantRole(RELAYER_ROLE, _admin);
        _grantRole(RELAYER_ROLE, _relayer);
        _setRoleAdmin(JUROR_ROLE, RELAYER_ROLE);
        _setRoleAdmin(PREMIUM_USER_ROLE, RELAYER_ROLE);
        EIP712_DOMAIN_SEPARATOR = keccak256(abi.encode(EIP712_DOMAIN_TYPEHASH, keccak256("Zorah"), keccak256("2.0.0"), block.chainid, address(this)));
    }

    function getRoleMemberCount(bytes32 role) public view returns (uint256) {
        return _roleMembers[role].length;
    }

    function _grantRole(bytes32 role, address account) internal virtual override returns (bool) {
        bool granted = super._grantRole(role, account);
        if (granted && _roleMemberIndex[role][account] == 0) {
            _roleMembers[role].push(account);
            _roleMemberIndex[role][account] = _roleMembers[role].length;
        }
        return granted;
    }

    function _revokeRole(bytes32 role, address account) internal virtual override returns (bool) {
        bool revoked = super._revokeRole(role, account);
        if (revoked) {
            uint256 idx = _roleMemberIndex[role][account];
            if (idx != 0) {
                uint256 lastIndex = _roleMembers[role].length;
                address last = _roleMembers[role][lastIndex - 1];
                _roleMembers[role][idx - 1] = last;
                _roleMemberIndex[role][last] = idx;
                _roleMembers[role].pop();
                delete _roleMemberIndex[role][account];
            }
        }
        return revoked;
    }

    function updateAdminWallet(address newAdmin) external onlyRole(ADMIN_ROLE) {
        require(newAdmin != address(0), "Invalid");
        adminWallet = newAdmin;
    }

    function addJuror(address juror) external onlyRole(RELAYER_ROLE) {
        require(getRoleMemberCount(JUROR_ROLE) < MAX_JURORS, "Max jurors");
        grantRole(JUROR_ROLE, juror);
    }

    function removeJuror(address juror) external onlyRole(RELAYER_ROLE) {
        revokeRole(JUROR_ROLE, juror);
    }

    function addPremiumUser(address user) external onlyRole(RELAYER_ROLE) {
        grantRole(PREMIUM_USER_ROLE, user);
    }

    function removePremiumUser(address user) external onlyRole(RELAYER_ROLE) {
        revokeRole(PREMIUM_USER_ROLE, user);
    }

    function updateConfig(
        bool updateEscrowFeePercentage, uint256 _feePercentage,
        bool updateMaxEscrowFee, uint256 _maxFee,
        bool updateDisputePenaltyPercentage, uint256 _percentage,
        bool updateQuorumPercentage, uint256 _quorumPercentage,
        bool updateLpRewardFeePercentage, uint256 _lpRewardFeePercentage
    ) external onlyRole(ADMIN_ROLE) {
        if (updateEscrowFeePercentage) {
            require(_feePercentage <= 1000, "Fee too high");
            escrowFeePercentage = _feePercentage;
        }
        if (updateMaxEscrowFee) {
            require(_maxFee > 0, "Invalid");
            maxEscrowFee = _maxFee;
        }
        if (updateDisputePenaltyPercentage) {
            require(_percentage <= 20, "Penalty high");
            disputePenaltyPercentage = _percentage;
        }
        if (updateQuorumPercentage) {
            require(_quorumPercentage <= 100 && _quorumPercentage > 0, "Invalid");
            quorumPercentage = _quorumPercentage;
        }
        if (updateLpRewardFeePercentage) {
            require(_lpRewardFeePercentage <= 1000, "LP fee high");
            uint256 old = lpRewardFeePercentage;
            lpRewardFeePercentage = _lpRewardFeePercentage;
            emit LpRewardFeePercentageUpdated(old, _lpRewardFeePercentage);
        }
    }

    function setBlacklistStatus(address user, bool status) external onlyRole(RELAYER_ROLE) {
        isBlacklisted[user] = status;
    }

    function addSupportedToken(IERC20 token) external onlyRole(ADMIN_ROLE) {
        isSupportedToken[address(token)] = true;
    }

    function removeSupportedToken(IERC20 token) external onlyRole(ADMIN_ROLE) {
        isSupportedToken[address(token)] = false;
    }

    function deposit(IERC20 token, uint256 amount, bool toVault) external validToken(token) notBlacklisted(msg.sender) nonReentrant whenNotPaused {
        require(amount > 0, "Invalid");
        if (toVault) require(hasRole(PREMIUM_USER_ROLE, msg.sender), "Not premium");
        
        token.safeTransferFrom(msg.sender, address(this), amount);
        _totalTokenBalances[token] += amount;
        
        if (toVault) {
            automatedVaults[msg.sender][token] += amount;
        } else {
            userBalances[msg.sender][token] += amount;
        }
        emit Deposited(msg.sender, token, amount);
    }

    function depositWithSignature(address user, IERC20 token, uint256 amount, bool toVault, uint8 v, bytes32 r, bytes32 s) private {
        bytes32 structHash = keccak256(abi.encode(EIP712_DEPOSIT_TYPEHASH, user, address(token), amount, _depositNonces[user]));
        bytes32 digest = keccak256(abi.encodePacked("\x19\x01", EIP712_DOMAIN_SEPARATOR, structHash));
        address signer = ecrecover(digest, v, r, s);
        require(signer == user && signer != address(0), "Invalid deposit sig");
        _depositNonces[user]++;
        
        token.safeTransferFrom(user, address(this), amount);
        _totalTokenBalances[token] += amount;

        if (toVault) automatedVaults[user][token] += amount;
        else userBalances[user][token] += amount;
        
        
        emit Deposited(user, token, amount);
       
    }

    function depositWithAuthorization(address user, IERC20 token, uint256 amount, uint256 validAfter, uint256 validBefore, bytes32 authNonce, bool toVault, uint8 v, bytes32 r, bytes32 s) external validToken(token) notBlacklisted(user) nonReentrant whenNotPaused {
        require(amount > 0, "Invalid");
        if (toVault) require(hasRole(PREMIUM_USER_ROLE, user), "Not premium");

        IERC3009(address(token)).transferWithAuthorization(user, address(this), amount, validAfter, validBefore, authNonce, v, r, s);
        _totalTokenBalances[token] += amount;
        
        if (toVault) automatedVaults[user][token] += amount;
        else userBalances[user][token] += amount;
        
        emit Deposited(user, token, amount);
    }

    function withdrawToWallet(IERC20 token, uint256 amount, bool fromVault) external validToken(token) notBlacklisted(msg.sender) nonReentrant whenNotPaused {
        require(amount > 0, "Invalid");
        
        if (fromVault) {
            require(automatedVaults[msg.sender][token] >= amount, "Insufficient");
            automatedVaults[msg.sender][token] -= amount;
        } else {
            require(userBalances[msg.sender][token] >= amount, "Insufficient");
            userBalances[msg.sender][token] -= amount;
        }
        
        uint256 bal = token.balanceOf(address(this));
        if (bal < amount) {
            emit InsufficientContractFunds(token, amount, bal);
            revert("Insufficient");
        }
        
        _totalTokenBalances[token] -= amount;
        token.safeTransfer(msg.sender, amount);
        emit WithdrawnToWallet(msg.sender, token, amount);
    }

    function withdrawToWalletWithSignature(address user, IERC20 token, uint256 amount, bool fromVault, uint8 v, bytes32 r, bytes32 s) external validToken(token) notBlacklisted(user) nonReentrant whenNotPaused {
        require(amount > 0, "Invalid");

        bytes32 hash = keccak256(abi.encode(EIP712_WITHDRAW_TYPEHASH, user, address(token), amount, _withdrawNonces[user]));
        bytes32 digest = keccak256(abi.encodePacked("\x19\x01", EIP712_DOMAIN_SEPARATOR, hash));
        require(ecrecover(digest, v, r, s) == user, "Invalid sig");
        
        if (fromVault) {
            require(automatedVaults[user][token] >= amount, "Insufficient");
            automatedVaults[user][token] -= amount;
        } else {
            require(userBalances[user][token] >= amount, "Insufficient");
            userBalances[user][token] -= amount;
        }

        uint256 bal = token.balanceOf(address(this));
        if (bal < amount) {
            emit InsufficientContractFunds(token, amount, bal);
            revert("Insufficient");
        }

        _withdrawNonces[user]++;
        _totalTokenBalances[token] -= amount;
        token.safeTransfer(user, amount);
        emit WithdrawnToWallet(user, token, amount);
    }

    function withdrawFundsForYield(IERC20 token, uint256 amount, address to) external onlyRole(RELAYER_ROLE) validToken(token) nonReentrant {
        require(amount > 0, "Invalid");

        uint256 bal = token.balanceOf(address(this));
        if (bal < amount) {
            emit InsufficientContractFunds(token, amount, bal);
            revert("Insufficient");
        }

        require(_totalTokenBalances[token] >= amount, "Insufficient");
        _totalTokenBalances[token] -= amount;

        token.safeTransfer(to, amount);
        emit FundsWithdrawnForYield(token, amount, to);
    }

    function createEscrow(address seller, address buyer, IERC20 token, uint256 amount, uint256 duration, bool isAutomated) external onlyRole(RELAYER_ROLE) validToken(token) notBlacklisted(seller) notBlacklisted(buyer) nonReentrant whenNotPaused returns (uint256) {
        require(seller != buyer && amount > 0 && duration > 0 && duration <= MAX_ESCROW_DURATION, "Invalid");
        
        uint256 escrowId = ++_escrowIdCounter;
        if (isAutomated) {
            require(hasRole(PREMIUM_USER_ROLE, seller) && automatedVaults[seller][token] >= amount, "Invalid");
            automatedVaults[seller][token] -= amount;
        } else {
            require(userBalances[seller][token] >= amount, "Insufficient");
            userBalances[seller][token] -= amount;
        }

        escrows[escrowId] = Escrow(seller, buyer, token, amount, block.timestamp + duration, false, false, 0, isAutomated);
        emit EscrowCreated(escrowId, seller, buyer, amount, isAutomated);
        return escrowId;
    }

    function completeEscrow(uint256 escrowId, address destination, bool yield) external onlyRole(RELAYER_ROLE) nonReentrant whenNotPaused {
        Escrow storage e = escrows[escrowId];
        require(!e.isCompleted && !e.isCancelled && e.disputeId == 0, "Invalid");
        if (destination != address(0)) require(destination != e.seller, "Invalid");

        e.isCompleted = true;
        uint256 fee = 0;
 
        if (destination != address(0)) {
            fee = calculateEscrowFee(e.amount);
            uint256 amountAfterFee = e.amount - fee;
            
            if (yield) {
                // Store balance on smart contract for yield generation
                userBalances[destination][e.token] += amountAfterFee;
            } else {
                // Send directly to user's wallet
                e.token.safeTransfer(destination, amountAfterFee);
            }
            
            if (fee > 0) userBalances[adminWallet][e.token] += fee;
        } else {
            if (e.isAutomated) automatedVaults[e.buyer][e.token] += e.amount;
            else userBalances[e.buyer][e.token] += e.amount;
        }
        
        emit EscrowCompleted(escrowId, fee);
    }

    function cancelEscrow(uint256 escrowId) external onlyRole(RELAYER_ROLE) nonReentrant whenNotPaused {
        Escrow storage e = escrows[escrowId];
        require(!e.isCompleted && !e.isCancelled && e.disputeId == 0, "Invalid");
        e.isCancelled = true;
        
        if (e.isAutomated) automatedVaults[e.seller][e.token] += e.amount;
        else userBalances[e.seller][e.token] += e.amount;

        emit EscrowCancelled(escrowId);
    }

    function internalTransfer(address from, address to, IERC20 token, uint256 amount) external onlyRole(RELAYER_ROLE) validToken(token) notBlacklisted(from) notBlacklisted(to) nonReentrant whenNotPaused {
        require(from != to && amount > 0 && userBalances[from][token] >= amount, "Invalid");
        userBalances[from][token] -= amount;
        userBalances[to][token] += amount;
        emit InternalTransferred(from, to, token, amount);
    }

    function createDispute(uint256 escrowId) external onlyRole(RELAYER_ROLE) nonReentrant whenNotPaused returns (uint256) {
        Escrow storage e = escrows[escrowId];
        require(!e.isCompleted && !e.isCancelled && e.disputeId == 0 && getRoleMemberCount(JUROR_ROLE) > 0, "Invalid");

        uint256 disputeId = ++_disputeIdCounter;
        e.disputeId = disputeId;

        Dispute storage d = disputes[disputeId];
        d.escrowId = escrowId;
        d.disputedAmount = e.amount;
        d.deadline = block.timestamp + DISPUTE_DURATION;

        emit DisputeCreated(disputeId, escrowId, d.deadline);
        return disputeId;
    }

    function voteOnDispute(uint256 disputeId, bool vote) external notBlacklisted(msg.sender) nonReentrant whenNotPaused {
        Dispute storage d = disputes[disputeId];
        require(!d.isResolved && hasRole(JUROR_ROLE, msg.sender) && !d.hasVoted[msg.sender] && block.timestamp <= d.deadline, "Invalid");

        d.hasVoted[msg.sender] = true;
        d.voteChoice[msg.sender] = vote;
        d.voters.push(msg.sender);
        d.totalVotes++;
        if (vote) d.positiveVotes++;

        emit DisputeVoted(disputeId, msg.sender, vote);

        uint256 quorum = (quorumPercentage * getRoleMemberCount(JUROR_ROLE) + 99) / 100;
        if (d.totalVotes >= quorum && d.positiveVotes != (d.totalVotes - d.positiveVotes)) {
            _resolveDisputeInternal(disputeId, d.positiveVotes > (d.totalVotes - d.positiveVotes));
        }
    }

    function voteOnDisputeWithSignature(address juror, uint256 disputeId, bool vote, uint8 v, bytes32 r, bytes32 s) external nonReentrant whenNotPaused {
        require(!isBlacklisted[juror], "Blacklisted");
        Dispute storage d = disputes[disputeId];
        require(!d.isResolved && hasRole(JUROR_ROLE, juror) && !d.hasVoted[juror] && block.timestamp <= d.deadline, "Invalid");

        bytes32 hash = keccak256(abi.encode(EIP712_JURORVOTE_TYPEHASH, juror, disputeId, vote, _jurorVoteNonces[juror]));
        bytes32 digest = keccak256(abi.encodePacked("\x19\x01", EIP712_DOMAIN_SEPARATOR, hash));
        require(ecrecover(digest, v, r, s) == juror, "Invalid sig");

        _jurorVoteNonces[juror]++;
        d.hasVoted[juror] = true;
        d.voteChoice[juror] = vote;
        d.voters.push(juror);
        d.totalVotes++;
        if (vote) d.positiveVotes++;

        emit DisputeVoted(disputeId, juror, vote);

        uint256 quorum = (quorumPercentage * getRoleMemberCount(JUROR_ROLE) + 99) / 100;
        if (d.totalVotes >= quorum && d.positiveVotes != (d.totalVotes - d.positiveVotes)) {
            _resolveDisputeInternal(disputeId, d.positiveVotes > (d.totalVotes - d.positiveVotes));
        }
    }

    function resolveExpiredDispute(uint256 disputeId) external onlyRole(RELAYER_ROLE) nonReentrant whenNotPaused {
        Dispute storage d = disputes[disputeId];
        require(!d.isResolved && block.timestamp > d.deadline, "Invalid");
        _resolveDisputeInternal(disputeId, d.positiveVotes >= (d.totalVotes - d.positiveVotes));
    }

    function _resolveDisputeInternal(uint256 disputeId, bool sellerWins) private {
        Dispute storage d = disputes[disputeId];
        require(!d.isResolved, "Resolved");

        d.isResolved = true;
        d.sellerWins = sellerWins;
        escrows[d.escrowId].isCompleted = true;

        uint256 fee = (d.disputedAmount * disputePenaltyPercentage) / 100;
        uint256 rewardPool = fee - (fee * 10) / 100; // adminShare calculated inline
        uint256 remaining = d.disputedAmount - fee;

        // Determine tie: equal votes for seller and buyer
        bool isTie = (d.positiveVotes * 2 == d.totalVotes);

        if (isTie) {
            _handleTieDispute(disputeId, rewardPool, remaining, fee);
            return;
        }

        _handleNonTieDispute(disputeId, sellerWins, rewardPool, remaining, fee);
    }

    function _handleTieDispute(uint256 disputeId, uint256 rewardPool, uint256 remaining, uint256 fee) private {
        Dispute storage d = disputes[disputeId];
        Escrow storage e = escrows[d.escrowId];
        
        uint256 voterCount = d.voters.length;
        uint256 rewardPerWinner = voterCount > 0 && rewardPool > 0 ? rewardPool / voterCount : 0;
        
        if (rewardPerWinner > 0) {
            for (uint256 i = 0; i < voterCount; i++) {
                pendingRewards[disputeId][d.voters[i]] = rewardPerWinner;
            }
        }

        uint256 adminTotal = (fee * 10) / 100 + rewardPool - (rewardPerWinner * voterCount) + remaining;
        userBalances[adminWallet][e.token] += adminTotal;

        emit DisputeTieAdminHold(disputeId, e.token, remaining);
        emit DisputeResolved(disputeId, d.sellerWins, rewardPool, bytes32("ADMIN"));
    }

    function _handleNonTieDispute(uint256 disputeId, bool sellerWins, uint256 rewardPool, uint256 remaining, uint256 fee) private {
        Dispute storage d = disputes[disputeId];
        Escrow storage e = escrows[d.escrowId];
        
        uint256 winnerCount = 0;
        uint256 voterCount = d.voters.length;
        
        // Count winners
        for (uint256 i = 0; i < voterCount; i++) {
            if (d.voteChoice[d.voters[i]] == sellerWins) winnerCount++;
        }

        uint256 rewardPerWinner = winnerCount > 0 && rewardPool > 0 ? rewardPool / winnerCount : 0;
        
        // Distribute rewards to winners
        if (rewardPerWinner > 0) {
            for (uint256 i = 0; i < voterCount; i++) {
                if (d.voteChoice[d.voters[i]] == sellerWins) {
                    pendingRewards[disputeId][d.voters[i]] = rewardPerWinner;
                }
            }
        }

        uint256 adminTotal = (fee * 10) / 100 + rewardPool - (rewardPerWinner * winnerCount);
        userBalances[adminWallet][e.token] += adminTotal;

        // Distribute remaining funds
        bytes32 fundDisbursement;
        if (sellerWins) {
            if (e.isAutomated) automatedVaults[e.seller][e.token] += remaining;
            else userBalances[e.seller][e.token] += remaining;
            fundDisbursement = bytes32("SELLER");
        } else {
            userBalances[e.buyer][e.token] += remaining;
            fundDisbursement = bytes32("BUYER");
        }

        emit DisputeResolved(disputeId, sellerWins, rewardPool, fundDisbursement);
    }

    function claimDisputeReward(uint256 disputeId) external notBlacklisted(msg.sender) nonReentrant whenNotPaused {
        uint256 reward = pendingRewards[disputeId][msg.sender];
        require(reward > 0 && !disputeRewarded[disputeId][msg.sender], "No reward");

        disputeRewarded[disputeId][msg.sender] = true;
        delete pendingRewards[disputeId][msg.sender];

        IERC20 token = escrows[disputes[disputeId].escrowId].token;
        userBalances[msg.sender][token] += reward;
        emit RewardClaimed(disputeId, msg.sender, reward);
    }

    function adminWithdraw(IERC20 token, uint256 amount) external onlyRole(ADMIN_ROLE) nonReentrant {
        require(amount > 0, "Invalid amount");
        require(userBalances[adminWallet][token] >= amount, "Insufficient");
    
        uint256 bal = token.balanceOf(address(this));
        if (bal < amount) {
            emit InsufficientContractFunds(token, amount, bal);
            revert("Insufficient");
        }

        userBalances[adminWallet][token] -= amount;
        _totalTokenBalances[token] -= amount;

        token.safeTransfer(adminWallet, amount);
        emit AdminWithdrawal(address(token), amount);
    }

    function calculateEscrowFee(uint256 amount) public view returns (uint256) {
        uint256 fee = (amount * escrowFeePercentage) / BASIS_POINTS;
        return fee > maxEscrowFee ? maxEscrowFee : fee;
    }

    function getUserBalance(address user, IERC20 token) external view returns (uint256) {
        return userBalances[user][token];
    }

    function getAutomatedVaultBalance(address user, IERC20 token) external view returns (uint256) {
        return automatedVaults[user][token];
    }

    function getTotalTokenBalance(IERC20 token) external view returns (uint256) {
        return _totalTokenBalances[token];
    }

    function getContractTokenBalance(IERC20 token) external view returns (uint256) {
        return token.balanceOf(address(this));
    }

    function _disbursementFor(uint256 disputeId) internal view returns (bytes32) {
        if (disputeId == 0) return bytes32(0);
        Dispute storage d = disputes[disputeId];
        if (!d.isResolved) return bytes32(0);
        bool isTie = (d.positiveVotes * 2 == d.totalVotes);
        if (isTie) return bytes32("ADMIN");
        return d.sellerWins ? bytes32("SELLER") : bytes32("BUYER");
    }

    function getEscrow(uint256 escrowId) external view returns (address seller, address buyer, address token, uint256 amount, uint256 expiryTime, bool isCompleted, bool isCancelled, uint256 disputeId, bool isAutomated, bytes32 fundDisbursement) {
        Escrow storage e = escrows[escrowId];
        seller = e.seller;
        buyer = e.buyer;
        token = address(e.token);
        amount = e.amount;
        expiryTime = e.expiryTime;
        isCompleted = e.isCompleted;
        isCancelled = e.isCancelled;
        disputeId = e.disputeId;
        isAutomated = e.isAutomated;
        fundDisbursement = _disbursementFor(disputeId);
    }

     function getDisputeVotes(uint256 disputeId) external view returns (uint256 totalVotes, uint256 positiveVotes, uint256 negativeVotes, bool isResolved, bool sellerWins, uint256 deadline) {
        Dispute storage dispute = disputes[disputeId];
        return (dispute.totalVotes, dispute.positiveVotes, dispute.totalVotes - dispute.positiveVotes, dispute.isResolved, dispute.sellerWins, dispute.deadline);
    }

    function getUserVoteDetails(uint256 disputeId, address user) external view returns (bool hasVoted, bool voteChoice) {
        require(disputes[disputeId].escrowId != 0, "Invalid dispute");
        Dispute storage d = disputes[disputeId];
        return (d.hasVoted[user], d.voteChoice[user]);
    }

    function getPendingReward(uint256 disputeId, address user) external view returns (uint256 amount, address token, bool claimed) {
        uint256 rewardAmount = pendingRewards[disputeId][user];
        IERC20 rewardToken = escrows[disputes[disputeId].escrowId].token;
        return (rewardAmount, address(rewardToken), disputeRewarded[disputeId][user]);
    }

    function getDepositNonce(address user) external view returns (uint256) {
        return _depositNonces[user];
    }

    function getWithdrawNonce(address user) external view returns (uint256) {
        return _withdrawNonces[user];
    }

    function getVoteNonce(address juror) external view returns (uint256) {
        return _jurorVoteNonces[juror];
    }

    function getConfiguration() external view returns (uint256 _escrowFeePercentage, uint256 _maxEscrowFee, uint256 _disputePenaltyPercentage, uint256 _quorumPercentage, uint256 _lpRewardFeePercentage, address _adminWallet) {
        return (escrowFeePercentage, maxEscrowFee, disputePenaltyPercentage, quorumPercentage, lpRewardFeePercentage, adminWallet);
    }

     function pause() external onlyRole(ADMIN_ROLE) {
        _pause();
    }

    function unpause() external onlyRole(ADMIN_ROLE) {
        _unpause();
    }
}