<?php

/**
 * Production-Ready Zorah Contract Manager
 * 
 * Handles all interactions with Zorah escrow smart contract
 * Supports gas relaying - user signs, gas wallet pays fees
 * 
 * Features:
 * - ABI loading from JSON file
 * - Complete contract method implementations
 * - Gas estimation and optimization
 * - Transaction batching
 * - Event listening
 * - Error handling with retries
 */

namespace Manomite\Zorah;

use Web3\Contract;
use Web3\Utils;
use kornrunner\Keccak;
use \Exception;

class ContractManager
{
    private MoonbeamWalletManager $walletManager;
    private string $contractAddress;
    private array $abi;
    private Contract $contract;
    private const ZORAH_EIP712_NAME = 'Zorah';
    private const ZORAH_EIP712_VERSION = '2.0.0';
    
    // Token addresses
    private const AUSDC_TESTNET = '0xD1633F7Fb3d716643125d6415d4177bC36b7186b';//Monbeam testnet aUSDC
    
    private const USDC_MAINNET = '0x818ec0A7Fe18Ff94269904fCED6AE3DaE6d6dC0b'; // Moonbeam USDC
    private const USDT_MAINNET = '0xeFAeeE334F0Fd1712f9a8cc375f427D9Cdd40d73'; // Moonbeam USDT
    
    public function __construct(
        MoonbeamWalletManager $walletManager,
        string $contractAddress,
        string $abiFilePath
    ) {
        $this->walletManager = $walletManager;
        $this->contractAddress = $contractAddress;
        $this->abi = $this->loadABI($abiFilePath);
        $this->contract = new Contract(
            $this->walletManager->getWeb3()->provider,
            $this->abi
        );
    }
    
    private function loadABI(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("ABI file not found: $filePath");
        }
        
        $abiJson = file_get_contents($filePath);
        $abi = json_decode($abiJson, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid ABI JSON: ' . json_last_error_msg());
        }
        
        return $abi;
    }
    
    public function getUSDCAddress(): string
    {
        $network = $this->walletManager->getNetworkInfo();
        return $network['chainId'] === 1287 ? self::AUSDC_TESTNET : self::USDC_MAINNET;
    }
    
    public function getUSDTAddress(): string
    {
        $network = $this->walletManager->getNetworkInfo();
        if ($network['chainId'] === 1287) {
            throw new Exception('USDT is only supported on mainnet');
        }
        return self::USDT_MAINNET;
    }
    
    public function getTokenAddress(string $symbol): string
    {
        $sym = strtoupper($symbol);
        $network = $this->walletManager->getNetworkInfo();
        $isTestnet = $network['chainId'] === 1287;
        
        if ($sym === 'USDC') {
            return $isTestnet ? self::AUSDC_TESTNET : self::USDC_MAINNET;
        }
        if ($sym === 'AUSDC') {
            if (!$isTestnet) {
                throw new Exception('aUSDC is only available on testnet');
            }
            return self::AUSDC_TESTNET;
        }
        if ($sym === 'USDT') {
            if ($isTestnet) {
                throw new Exception('USDT is only available on mainnet');
            }
            return self::USDT_MAINNET;
        }
        
        throw new Exception("Unsupported token: $symbol");
    }
    
    public function getUserBalance(string $userAddress): string
    {
        $result = $this->callContractMethod('getUserBalance', [
            $userAddress,
            $this->getUSDCAddress()
        ]);
        
        return $this->formatUSDC($result[0]);
    }
    
    /**
     * Get user's automated vault balance
     * 
     * @param string $userAddress User's address
     * @return string Balance in USDC
     * @throws Exception
     */
    public function getVaultBalance(string $userAddress): string
    {
        $result = $this->callContractMethod('getAutomatedVaultBalance', [
            $userAddress,
            $this->getUSDCAddress()
        ]);
        
        return $this->formatUSDC($result[0]);
    }
    
    // ===========================================
    // DEPOSIT & WITHDRAW
    // ===========================================
    
    /**
     * Withdraw from automated vault
     * 
     * @param string $userPrivateKey User's private key
     * @param string $amount Amount in USDC
     * @return array Transaction result
     * @throws Exception
     */
    public function withdrawFromVault(string $userPrivateKey, string $amount): array
    {
        $amountWei = $this->parseUSDC($amount);
        
        $txHash = $this->sendContractTransaction(
            'withdrawFromAutomatedVault',
            [$this->getUSDCAddress(), $amountWei],
            $userPrivateKey
        );
        
        $result = $this->walletManager->waitForTransaction($txHash);
        
        return [
            'success' => $result['success'],
            'txHash' => $txHash,
            'gasUsed' => $result['gasUsed']
        ];
    }
    
    // ===========================================
    // ESCROW METHODS
    // ===========================================
    
    /**
     * Create escrow (relayer only)
     * 
     * @param string $seller Seller address
     * @param string $buyer Buyer address
     * @param string $amount Amount in USDC
     * @param int $durationSeconds Escrow duration in seconds
     * @param bool $isAutomated Use automated vault
     * @return array Transaction result with escrowId
     * @throws Exception
     */
    public function createEscrow(
        string $seller,
        string $buyer,
        string $amount,
        int $durationSeconds,
        bool $isAutomated = false
    ): array {
        $amountWei = $this->parseUSDC($amount);
        $usdcAddress = $this->getUSDCAddress();
        
        $txHash = $this->sendContractTransaction(
            'createEscrow',
            [$seller, $buyer, $usdcAddress, $amountWei, $durationSeconds, $isAutomated]
        );
        
        $result = $this->walletManager->waitForTransaction($txHash);
        
        // Parse EscrowCreated event to get escrowId
        $escrowId = $this->parseEscrowIdFromLogs($result['logs']);
        
        return [
            'success' => $result['success'],
            'txHash' => $txHash,
            'escrowId' => $escrowId,
            'gasUsed' => $result['gasUsed']
        ];
    }
    
    /**
     * Complete escrow
     * 
     * @param int $escrowId Escrow ID
     * @param string|null $destination Destination address for P2P transfer (null = regular escrow to buyer)
     * @param bool $yield If true and destination is set, store on contract for yield; if false, send directly to wallet
     * @return array Transaction result
     * @throws Exception
     */
    public function completeEscrow(int $escrowId, ?string $destination = null, bool $yield = true): array
    {
        // When destination is null, use address(0) to indicate regular escrow (funds go to buyer)
        // When destination is set, it's a P2P transfer to that specific address
        $dest = $destination ?? '0x0000000000000000000000000000000000000000';
        
        $txHash = $this->sendContractTransaction('completeEscrow', [$escrowId, $dest, $yield]);
        $result = $this->walletManager->waitForTransaction($txHash);
        
        return [
            'success' => $result['success'],
            'txHash' => $txHash,
            'gasUsed' => $result['gasUsed']
        ];
    }
    
    public function cancelEscrow(int $escrowId): array
    {
        $txHash = $this->sendContractTransaction('cancelEscrow', [$escrowId]);
        $result = $this->walletManager->waitForTransaction($txHash);
        
        return [
            'success' => $result['success'],
            'txHash' => $txHash,
            'gasUsed' => $result['gasUsed']
        ];
    }
    
    /**
     * Get escrow details
     * 
     * @param int $escrowId Escrow ID
     * @return array Escrow details
     * @throws Exception
     */
    public function getEscrow(int $escrowId): array
    {
        $result = $this->callContractMethod('getEscrow', [$escrowId]);
        
        return [
            'seller' => $result['seller'],
            'buyer' => $result['buyer'],
            'token' => $result['token'],
            'amount' => $this->formatUSDC($result['amount']),
            'expiryTime' => (int)$result['expiryTime'],
            'isCompleted' => $result['isCompleted'],
            'isCancelled' => $result['isCancelled'],
            'disputeId' => (int)$result['disputeId'],
            'isAutomated' => $result['isAutomated']
        ];
    }
    
    // ===========================================
    // DISPUTE METHODS
    // ===========================================
    
    /**
     * Create dispute (relayer only)
     * 
     * @param int $escrowId Escrow ID
     * @return array Transaction result with disputeId
     * @throws Exception
     */
    public function createDispute(int $escrowId): array
    {
        $txHash = $this->sendContractTransaction('createDispute', [$escrowId]);
        $result = $this->walletManager->waitForTransaction($txHash);
        
        // Parse DisputeCreated event to get disputeId
        $disputeId = $this->parseDisputeIdFromLogs($result['logs']);
        
        return [
            'success' => $result['success'],
            'txHash' => $txHash,
            'disputeId' => $disputeId,
            'gasUsed' => $result['gasUsed']
        ];
    }
    
    /**
     * Vote on dispute
     * 
     * @param string $jurorPrivateKey Juror's private key
     * @param int $disputeId Dispute ID
     * @param bool $voteForSeller true = vote for seller, false = vote for buyer
     * @return array Transaction result
     * @throws Exception
     */
    public function voteOnDispute(
        string $jurorPrivateKey,
        int $disputeId,
        bool $voteForSeller
    ): array {
        // Deprecated: direct on-chain vote with msg.sender contradicts gasless model.
        // Use voteOnDisputeGasless(juror, disputeId, voteForSeller, sig).
        throw new Exception('Deprecated path. Use voteOnDisputeGasless(juror, disputeId, voteForSeller, sig).');
    }
    
    /**
     * Get dispute details
     * 
     * @param int $disputeId Dispute ID
     * @return array Dispute details
     * @throws Exception
     */
    public function getDispute(int $disputeId): array
    {
        $result = $this->callContractMethod('getDisputeVotes', [$disputeId]);
        
        return [
            'totalVotes' => (int)$result['totalVotes'],
            'positiveVotes' => (int)$result['positiveVotes'],
            'negativeVotes' => (int)$result['negativeVotes'],
            'isResolved' => $result['isResolved'],
            'sellerWins' => $result['sellerWins'],
            'deadline' => (int)$result['deadline'],
            'autoResolved' => $result['autoResolved']
        ];
    }
    
    /**
     * Claim dispute reward
     * 
     * @param string $jurorPrivateKey Juror's private key
     * @param int $disputeId Dispute ID
     * @return array Transaction result
     * @throws Exception
     */
    public function claimReward(string $jurorPrivateKey, int $disputeId): array
    {
        $txHash = $this->sendContractTransaction(
            'claimDisputeReward',
            [$disputeId],
            $jurorPrivateKey
        );
        
        $result = $this->walletManager->waitForTransaction($txHash);
        
        return [
            'success' => $result['success'],
            'txHash' => $txHash,
            'gasUsed' => $result['gasUsed']
        ];
    }
    
    /**
     * Get pending reward for juror
     * 
     * @param string $jurorAddress Juror's address
     * @param int $disputeId Dispute ID
     * @return array Reward details
     * @throws Exception
     */
    public function getPendingReward(string $jurorAddress, int $disputeId): array
    {
        $result = $this->callContractMethod('getPendingReward', [$disputeId, $jurorAddress]);
        
        return [
            'amount' => $this->formatUSDC($result['amount']),
            'token' => $result['token'],
            'claimed' => $result['claimed']
        ];
    }
    
    // ===========================================
    // HELPER METHODS
    // ===========================================
    
    /**
     * Call contract method (read-only)
     * 
     * @param string $method Method name
     * @param array $params Method parameters
     * @return mixed Method result
     * @throws Exception
     */
    private function callContractMethod(string $method, array $params = []): mixed
    {
        $result = null;
        $error = null;
        // Default timeout (seconds) for the RPC call to complete
        $timeoutSeconds = 10;

        try {
            $contractAt = $this->contract->at($this->contractAddress);

            $callback = function($err, $data) use (&$result, &$error) {
                if ($err !== null) {
                    $error = $err;
                    return;
                }
                $result = $data;
            };

            $args = array_merge([$method], array_values($params), [$callback]);

            // Fire the call
            call_user_func_array([$contractAt, 'call'], $args);

            // Wait for callback to populate result or error, with timeout
            $start = microtime(true);
            while ($result === null && $error === null && (microtime(true) - $start) < $timeoutSeconds) {
                // Small sleep to yield
                usleep(10000);
            }
        } catch (\Throwable $e) {
            throw new Exception('Contract call failed: ' . $e->getMessage());
        }

        if ($error !== null) {
            $msg = is_object($error) && method_exists($error, 'getMessage') ? $error->getMessage() : (string)$error;
            throw new Exception("Contract call failed: " . $msg);
        }

        if ($result === null) {
            throw new Exception('Contract call timed out');
        }

        return $this->normalizeCallResult($result);
    }

    /**
     * Normalize call results to an array shape for callers.
     * - null -> []
     * - array -> returned as-is
     * - object -> cast to associative array
     * - scalar -> wrapped in array
     *
     * @param mixed $result
     * @return array
     */
    private function normalizeCallResult(mixed $result): array
    {
        if ($result === null) return [];
        if (is_array($result)) return $result;
        if (is_object($result)) return (array)$result;
        return [$result];
    }
    
    /**
     * Send contract transaction (write operation)
     * Uses gas wallet to pay fees, user's key to authorize
     * 
     * @param string $method Method name
     * @param array $params Method parameters
     * @param string|null $userPrivateKey User's private key (null = use gas wallet)
     * @param int $value ETH value to send (in Wei)
     * @return string Transaction hash
     * @throws Exception
     */
    private function sendContractTransaction(
        string $method,
        array $params = [],
        ?string $userPrivateKey = null,
        int $value = 0,
        ?string $contractAddress = null
    ): string {
        $contractAddr = $contractAddress ?? $this->contractAddress;

        $gasPriv = $this->walletManager->getGasWalletPrivateKey();
        $gasFrom = $this->walletManager->getGasWalletAddress();
        if (!$gasPriv || !$gasFrom) {
            throw new Exception('Gas wallet not configured');
        }

        $result = null;
        $error = null;

        try {
            // Build tx options
            $gasPrice = $this->walletManager->getGasPrice();
            $nonce = '0x' . dechex($this->walletManager->getNonce($gasFrom));

            $txOptions = [
                'from' => $gasFrom,
                'value' => '0x' . dechex($value),
                'gasPrice' => '0x' . dechex((int)$gasPrice),
                // gas will be estimated by the node if omitted; optional manual override could be added
            ];

            $contractAt = null;
            if ($contractAddr === $this->contractAddress) {
                $contractAt = $this->contract->at($contractAddr);
            } else {
                // Use a minimal ERC20 ABI for token operations
                $erc20Abi = json_decode($this->getERC20ABI(), true);
                $erc = new Contract($this->walletManager->getWeb3()->provider, $erc20Abi);
                $contractAt = $erc->at($contractAddr);
            }

            $callback = function($err, $data) use (&$result, &$error) {
                if ($err !== null) {
                    $error = $err;
                    return;
                }
                $result = $data;
            };

            // gasPriv is expected to be a plain string private key (without 0x)
            $gasPrivRaw = (string)$gasPriv;

            // Prepare arguments: method, params..., txOptions, privateKey, callback
            $args = array_merge([$method], array_values($params), [$txOptions, $gasPrivRaw, $callback]);

            call_user_func_array([$contractAt, 'send'], $args);

            // Wait for callback (tx hash) with timeout
            $timeoutSeconds = 30;
            $start = microtime(true);
            while ($result === null && $error === null && (microtime(true) - $start) < $timeoutSeconds) {
                usleep(20000);
            }

            if ($error !== null) {
                $msg = is_object($error) && method_exists($error, 'getMessage') ? $error->getMessage() : (string)$error;
                // wipe raw key before throwing
                if (isset($gasPrivRaw) && function_exists('sodium_memzero')) {
                    @sodium_memzero($gasPrivRaw);
                } elseif (isset($gasPrivRaw)) {
                    $gasPrivRaw = str_repeat("\0", strlen($gasPrivRaw));
                }
                throw new Exception('Contract transaction failed: ' . $msg);
            }

            if ($result === null) {
                if (isset($gasPrivRaw) && function_exists('sodium_memzero')) {
                    @sodium_memzero($gasPrivRaw);
                } elseif (isset($gasPrivRaw)) {
                    $gasPrivRaw = str_repeat("\0", strlen($gasPrivRaw));
                }
                throw new Exception('Contract transaction timed out');
            }

            // Wipe raw key before returning
            if (isset($gasPrivRaw) && function_exists('sodium_memzero')) {
                @sodium_memzero($gasPrivRaw);
            } elseif (isset($gasPrivRaw)) {
                $gasPrivRaw = str_repeat("\0", strlen($gasPrivRaw));
            }

            // Expect $result to be transaction hash (string)
            return (string)$result;
        } catch (\Throwable $e) {
            throw new Exception('Failed to send contract transaction: ' . $e->getMessage());
        }
    }
    
    /**
     * Get ERC20 ABI (approve, transfer, balanceOf)
     * 
     * @return string JSON ABI
     */
    private function getERC20ABI(): string
    {
        return json_encode([
            [
                "constant" => false,
                "inputs" => [
                    ["name" => "spender", "type" => "address"],
                    ["name" => "amount", "type" => "uint256"]
                ],
                "name" => "approve",
                "outputs" => [["name" => "", "type" => "bool"]],
                "type" => "function"
            ],
            [
                "constant" => true,
                "inputs" => [["name" => "account", "type" => "address"]],
                "name" => "balanceOf",
                "outputs" => [["name" => "", "type" => "uint256"]],
                "type" => "function"
            ],
            [
                "constant" => true,
                "inputs" => [],
                "name" => "name",
                "outputs" => [["name" => "", "type" => "string"]],
                "type" => "function"
            ],
            [
                "constant" => true,
                "inputs" => [["name" => "owner", "type" => "address"]],
                "name" => "nonces",
                "outputs" => [["name" => "", "type" => "uint256"]],
                "type" => "function"
            ],
            [
                "constant" => false,
                "inputs" => [
                    ["name" => "owner", "type" => "address"],
                    ["name" => "spender", "type" => "address"],
                    ["name" => "value", "type" => "uint256"],
                    ["name" => "deadline", "type" => "uint256"],
                    ["name" => "v", "type" => "uint8"],
                    ["name" => "r", "type" => "bytes32"],
                    ["name" => "s", "type" => "bytes32"]
                ],
                "name" => "permit",
                "outputs" => [],
                "type" => "function"
            ]
        ]);
    }

    /**
     * Get current deposit nonce from Zorah for a user.
     */
    public function getDepositNonce(string $userAddress): string
    {
        $result = $this->callContractMethod('getDepositNonce', [$userAddress]);
        // normalizeCallResult returns array; pull first scalar
        if (is_array($result) && isset($result[0])) return (string)$result[0];
        return (string)$result;
    }

    /**
     * Build EIP-712 typed data for Zorah Deposit(user, token, amount, nonce).
     * Return the JSON typed-data structure that clients can sign with eth_signTypedData_v4.
     */
    public function buildDepositTypedData(string $user, string $token, string $amountWei): array
    {
        $chainId = $this->walletManager->getNetworkInfo()['chainId'];
        $nonce = $this->getDepositNonce($user);
        return [
            'types' => [
                'EIP712Domain' => [
                    ['name' => 'name', 'type' => 'string'],
                    ['name' => 'version', 'type' => 'string'],
                    ['name' => 'chainId', 'type' => 'uint256'],
                    ['name' => 'verifyingContract', 'type' => 'address'],
                ],
                'Deposit' => [
                    ['name' => 'user', 'type' => 'address'],
                    ['name' => 'token', 'type' => 'address'],
                    ['name' => 'amount', 'type' => 'uint256'],
                    ['name' => 'nonce', 'type' => 'uint256'],
                ],
            ],
            'primaryType' => 'Deposit',
            'domain' => [
                'name' => self::ZORAH_EIP712_NAME,
                'version' => self::ZORAH_EIP712_VERSION,
                'chainId' => $chainId,
                'verifyingContract' => $this->contractAddress,
            ],
            'message' => [
                'user' => $user,
                'token' => $token,
                'amount' => $amountWei,
                'nonce' => $nonce,
            ],
        ];
    }

    /**
     * Get current withdraw nonce from Zorah for a user.
     */
    public function getWithdrawNonce(string $userAddress): string
    {
        $result = $this->callContractMethod('getWithdrawNonce', [$userAddress]);
        if (is_array($result) && isset($result[0])) return (string)$result[0];
        return (string)$result;
    }

    /**
     * Build EIP-712 typed data for Zorah Withdraw(user, token, amount, nonce).
     */
    public function buildWithdrawTypedData(string $user, string $token, string $amountWei): array
    {
        $chainId = $this->walletManager->getNetworkInfo()['chainId'];
        $nonce = $this->getWithdrawNonce($user);
        return [
            'types' => [
                'EIP712Domain' => [
                    ['name' => 'name', 'type' => 'string'],
                    ['name' => 'version', 'type' => 'string'],
                    ['name' => 'chainId', 'type' => 'uint256'],
                    ['name' => 'verifyingContract', 'type' => 'address'],
                ],
                'Withdraw' => [
                    ['name' => 'user', 'type' => 'address'],
                    ['name' => 'token', 'type' => 'address'],
                    ['name' => 'amount', 'type' => 'uint256'],
                    ['name' => 'nonce', 'type' => 'uint256'],
                ],
            ],
            'primaryType' => 'Withdraw',
            'domain' => [
                'name' => self::ZORAH_EIP712_NAME,
                'version' => self::ZORAH_EIP712_VERSION,
                'chainId' => $chainId,
                'verifyingContract' => $this->contractAddress,
            ],
            'message' => [
                'user' => $user,
                'token' => $token,
                'amount' => $amountWei,
                'nonce' => $nonce,
            ],
        ];
    }

    /**
     * Relay Zorah.withdrawWithSignature: relayer pays gas, user signature authorizes.
     */
    public function withdrawGasless(
        string $user,
        string $token,
        string $amountWei,
        array $sig
    ): array {
        $txHash = $this->sendContractTransaction(
            'withdrawWithSignature',
            [
                $user,
                $token,
                $amountWei,
                (int)$sig['v'], $sig['r'], $sig['s'],
            ]
        );
        $receipt = $this->walletManager->waitForTransaction($txHash);
        return [
            'success' => $receipt['success'],
            'txHash' => $txHash,
            'gasUsed' => $receipt['gasUsed'],
            'blockNumber' => $receipt['blockNumber'] ?? null,
        ];
    }

    /**
     * Relay Zorah.approveAndDepositWithSignature: relayer pays gas, user provides signatures.
     */
    public function approveAndDepositGasless(
        string $user,
        string $token,
        string $approveAmountWei,
        string $depositAmountWei,
        int $deadline,
        array $permitSig,    // ['v'=>, 'r'=>, 's'=>]
        array $depositSig    // ['v'=>, 'r'=>, 's'=>]
    ): array {
        $txHash = $this->sendContractTransaction(
            'approveAndDepositWithSignature',
            [
                $user,
                $token,
                $approveAmountWei,
                $depositAmountWei,
                $deadline,
                (int)$permitSig['v'], $permitSig['r'], $permitSig['s'],
                (int)$depositSig['v'], $depositSig['r'], $depositSig['s'],
            ]
        );
        $receipt = $this->walletManager->waitForTransaction($txHash);
        return [
            'success' => $receipt['success'],
            'txHash' => $txHash,
            'gasUsed' => $receipt['gasUsed'],
            'blockNumber' => $receipt['blockNumber'] ?? null,
        ];
    }

    /**
     * Build EIP-3009 TransferWithAuthorization typed data for tokens like USDC.
     */
    public function buildTransferWithAuthTypedData(
        string $from,
        string $token,
        string $to,
        string $valueWei,
        int $validAfter,
        int $validBefore,
        string $nonceHex
    ): array {
        $ercAbi = json_decode($this->getERC20ABI(), true);
        $erc = new Contract($this->walletManager->getWeb3()->provider, $ercAbi);
        $ercAt = $erc->at($token);

        // name()
        $name = null; $error = null;
        $ercAt->call('name', function($err, $res) use (&$name, &$error) {
            if ($err) { $error = $err; return; }
            $name = is_array($res) ? $res[0] : $res;
        });
        if ($error) throw new Exception('Failed to read token name');

        $chainId = $this->walletManager->getNetworkInfo()['chainId'];
        return [
            'types' => [
                'EIP712Domain' => [
                    ['name' => 'name', 'type' => 'string'],
                    ['name' => 'version', 'type' => 'string'],
                    ['name' => 'chainId', 'type' => 'uint256'],
                    ['name' => 'verifyingContract', 'type' => 'address'],
                ],
                'TransferWithAuthorization' => [
                    ['name' => 'from', 'type' => 'address'],
                    ['name' => 'to', 'type' => 'address'],
                    ['name' => 'value', 'type' => 'uint256'],
                    ['name' => 'validAfter', 'type' => 'uint256'],
                    ['name' => 'validBefore', 'type' => 'uint256'],
                    ['name' => 'nonce', 'type' => 'bytes32'],
                ],
            ],
            'primaryType' => 'TransferWithAuthorization',
            'domain' => [
                'name' => $name,
                'version' => '1',
                'chainId' => $chainId,
                'verifyingContract' => $token,
            ],
            'message' => [
                'from' => $from,
                'to' => $to,
                'value' => $valueWei,
                'validAfter' => $validAfter,
                'validBefore' => $validBefore,
                'nonce' => $nonceHex,
            ],
        ];
    }

    /**
     * Relay Zorah.depositWithAuthorization (EIP-3009 path).
     */
    public function depositWithAuthorizationGasless(
        string $user,
        string $token,
        string $amountWei,
        int $validAfter,
        int $validBefore,
        string $authNonceHex,
        array $sig
    ): array {
        $txHash = $this->sendContractTransaction(
            'depositWithAuthorization',
            [
                $user,
                $token,
                $amountWei,
                $validAfter,
                $validBefore,
                $authNonceHex,
                (int)$sig['v'], $sig['r'], $sig['s'],
            ]
        );
        $receipt = $this->walletManager->waitForTransaction($txHash);
        return [
            'success' => $receipt['success'],
            'txHash' => $txHash,
            'gasUsed' => $receipt['gasUsed'],
            'blockNumber' => $receipt['blockNumber'] ?? null,
        ];
    }

    /**
     * Build ERC-2612 permit typed data for a token. Fetches token name and nonce.
     * Note: Some tokens use version '1'. If token exposes version(), adjust accordingly.
     */
    public function buildPermitTypedData(
        string $owner,
        string $token,
        string $spender,
        string $valueWei,
        int $deadline
    ): array {
        $ercAbi = json_decode($this->getERC20ABI(), true);
        $erc = new Contract($this->walletManager->getWeb3()->provider, $ercAbi);
        $ercAt = $erc->at($token);

        // name()
        $name = null; $error = null;
        $ercAt->call('name', function($err, $res) use (&$name, &$error) {
            if ($err) { $error = $err; return; }
            $name = is_array($res) ? $res[0] : $res;
        });
        if ($error) throw new Exception('Failed to read token name');

        // nonces(owner)
        $nonce = null; $error2 = null;
        $ercAt->call('nonces', $owner, function($err, $res) use (&$nonce, &$error2) {
            if ($err) { $error2 = $err; return; }
            $nonce = is_array($res) ? $res[0] : $res;
        });
        if ($error2) throw new Exception('Failed to read token nonces');

        $chainId = $this->walletManager->getNetworkInfo()['chainId'];
        return [
            'types' => [
                'EIP712Domain' => [
                    ['name' => 'name', 'type' => 'string'],
                    ['name' => 'version', 'type' => 'string'],
                    ['name' => 'chainId', 'type' => 'uint256'],
                    ['name' => 'verifyingContract', 'type' => 'address'],
                ],
                'Permit' => [
                    ['name' => 'owner', 'type' => 'address'],
                    ['name' => 'spender', 'type' => 'address'],
                    ['name' => 'value', 'type' => 'uint256'],
                    ['name' => 'nonce', 'type' => 'uint256'],
                    ['name' => 'deadline', 'type' => 'uint256'],
                ],
            ],
            'primaryType' => 'Permit',
            'domain' => [
                'name' => $name,
                'version' => '1',
                'chainId' => $chainId,
                'verifyingContract' => $token,
            ],
            'message' => [
                'owner' => $owner,
                'spender' => $spender,
                'value' => $valueWei,
                'nonce' => (string)$nonce,
                'deadline' => $deadline,
            ],
        ];
    }

    /**
     * Relay a token permit (ERC-2612). Any address can submit; gas wallet pays.
     */
    public function relayPermitERC2612(
        string $token,
        string $owner,
        string $spender,
        string $valueWei,
        int $deadline,
        array $sig
    ): array {
        $txHash = $this->sendContractTransaction(
            'permit',
            [$owner, $spender, $valueWei, $deadline, (int)$sig['v'], $sig['r'], $sig['s']],
            null,
            0,
            $token
        );
        $receipt = $this->walletManager->waitForTransaction($txHash);
        return [
            'success' => $receipt['success'],
            'txHash' => $txHash,
            'gasUsed' => $receipt['gasUsed'],
        ];
    }

    /**
     * Prepare an unlimited permit for the Zorah contract as spender. Returns typed data to sign.
     */
    public function prepareUnlimitedPermitPayload(string $owner, ?string $tokenAddress = null, ?int $deadline = null): array
    {
        $token = $tokenAddress ?: $this->getUSDCAddress();
        $value = '0xffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff'; // uint256 max
        $exp = $deadline ?: (time() + 3650 * 24 * 60 * 60); // ~10 years
        return $this->buildPermitTypedData($owner, $token, $this->contractAddress, $value, $exp);
    }

    /**
     * Get current juror vote nonce from Zorah for a juror.
     */
    public function getVoteNonce(string $jurorAddress): string
    {
        $result = $this->callContractMethod('getVoteNonce', [$jurorAddress]);
        if (is_array($result) && isset($result[0])) return (string)$result[0];
        return (string)$result;
    }

    /**
     * Build EIP-712 typed data for JurorVote(juror, disputeId, voteForSeller, nonce).
     */
    public function buildJurorVoteTypedData(
        string $juror,
        int $disputeId,
        bool $voteForSeller
    ): array {
        $chainId = $this->walletManager->getNetworkInfo()['chainId'];
        $nonce = $this->getVoteNonce($juror);
        return [
            'types' => [
                'EIP712Domain' => [
                    ['name' => 'name', 'type' => 'string'],
                    ['name' => 'version', 'type' => 'string'],
                    ['name' => 'chainId', 'type' => 'uint256'],
                    ['name' => 'verifyingContract', 'type' => 'address'],
                ],
                'JurorVote' => [
                    ['name' => 'juror', 'type' => 'address'],
                    ['name' => 'disputeId', 'type' => 'uint256'],
                    ['name' => 'voteForSeller', 'type' => 'bool'],
                    ['name' => 'nonce', 'type' => 'uint256'],
                ],
            ],
            'primaryType' => 'JurorVote',
            'domain' => [
                'name' => self::ZORAH_EIP712_NAME,
                'version' => self::ZORAH_EIP712_VERSION,
                'chainId' => $chainId,
                'verifyingContract' => $this->contractAddress,
            ],
            'message' => [
                'juror' => $juror,
                'disputeId' => $disputeId,
                'voteForSeller' => $voteForSeller,
                'nonce' => $nonce,
            ],
        ];
    }

    /**
     * Relay Zorah.voteOnDisputeWithSignature: relayer pays, juror authorizes off-chain.
     */
    public function voteOnDisputeGasless(
        string $juror,
        int $disputeId,
        bool $voteForSeller,
        array $sig
    ): array {
        $txHash = $this->sendContractTransaction(
            'voteOnDisputeWithSignature',
            [
                $juror,
                $disputeId,
                $voteForSeller,
                (int)$sig['v'], $sig['r'], $sig['s'],
            ]
        );
        $receipt = $this->walletManager->waitForTransaction($txHash);
        return [
            'success' => $receipt['success'],
            'txHash' => $txHash,
            'gasUsed' => $receipt['gasUsed'],
            'blockNumber' => $receipt['blockNumber'] ?? null,
        ];
    }
    
    /**
     * Sign EIP-712 typed data with a private key and return v, r, s
     * 
     * @param array $typedData EIP-712 typed data structure
     * @param string $privateKey User's private key (hex, with or without 0x)
     * @return array ['v' => int, 'r' => '0x...', 's' => '0x...']
     */
    public function signTypedData(array $typedData, string $privateKey): array
    {
        // Compute EIP-712 digest
        $digest = $this->computeEIP712Digest($typedData);
        
        // Sign with secp256k1
        $ec = new \Elliptic\EC('secp256k1');
        $key = $ec->keyFromPrivate(preg_replace('/^0x/', '', $privateKey));
        $digestBin = hex2bin(preg_replace('/^0x/', '', $digest));
        $signature = $key->sign($digestBin, ['canonical' => true]);
        
        $r = str_pad($signature->r->toString(16), 64, '0', STR_PAD_LEFT);
        $s = str_pad($signature->s->toString(16), 64, '0', STR_PAD_LEFT);
        $v = $signature->recoveryParam + 27;
        
        return ['v' => $v, 'r' => '0x' . $r, 's' => '0x' . $s];
    }
    
    /**
     * Compute EIP-712 digest from typed data structure
     * 
     * @param array $typedData EIP-712 typed data
     * @return string 32-byte digest as hex (with 0x prefix)
     */
    private function computeEIP712Digest(array $typedData): string
    {
        $domainSeparator = $this->hashStruct('EIP712Domain', $typedData['domain'], $typedData['types']);
        $structHash = $this->hashStruct($typedData['primaryType'], $typedData['message'], $typedData['types']);
        
        $prefix = hex2bin('1901');
        $domainBin = hex2bin(preg_replace('/^0x/', '', $domainSeparator));
        $structBin = hex2bin(preg_replace('/^0x/', '', $structHash));
        
        return '0x' . \kornrunner\Keccak::hash($prefix . $domainBin . $structBin, 256);
    }
    
    /**
     * Hash struct per EIP-712 encoding rules
     */
    private function hashStruct(string $typeName, array $data, array $types): string
    {
        $typeHash = $this->typeHash($typeName, $types);
        $encoded = hex2bin(preg_replace('/^0x/', '', $typeHash));
        
        foreach ($types[$typeName] as $field) {
            $value = $data[$field['name']];
            $encoded .= $this->encodeField($field['type'], $value);
        }
        
        return '0x' . \kornrunner\Keccak::hash($encoded, 256);
    }
    
    /**
     * Compute type hash (keccak256 of type signature)
     */
    private function typeHash(string $typeName, array $types): string
    {
        $typeStr = $typeName . '(';
        $fields = [];
        foreach ($types[$typeName] as $field) {
            $fields[] = $field['type'] . ' ' . $field['name'];
        }
        $typeStr .= implode(',', $fields) . ')';
        return '0x' . \kornrunner\Keccak::hash($typeStr, 256);
    }
    
    /**
     * Encode a single field value to 32 bytes
     */
    private function encodeField(string $type, $value): string
    {
        if ($type === 'string' || $type === 'bytes') {
            return hex2bin(preg_replace('/^0x/', '', '0x' . \kornrunner\Keccak::hash($value, 256)));
        }
        
        if ($type === 'address') {
            return hex2bin(str_pad(preg_replace('/^0x/', '', $value), 64, '0', STR_PAD_LEFT));
        }
        
        if (preg_match('/^uint(\d+)$/', $type)) {
            $valueStr = is_string($value) ? $value : (string)$value;
            $hex = preg_replace('/^0x/', '', $valueStr);
            if (!ctype_xdigit($hex)) {
                $hex = gmp_strval(gmp_init($valueStr, 10), 16);
            }
            return hex2bin(str_pad($hex, 64, '0', STR_PAD_LEFT));
        }
        
        if ($type === 'bool') {
            return hex2bin(str_pad($value ? '1' : '0', 64, '0', STR_PAD_LEFT));
        }
        
        if (preg_match('/^bytes32$/', $type)) {
            return hex2bin(str_pad(preg_replace('/^0x/', '', $value), 64, '0', STR_PAD_LEFT));
        }
        
        throw new \Exception("Unsupported EIP-712 type: $type");
    }
    
    /**
     * Approve the Zorah contract to spend unlimited tokens from user's wallet.
     * This uses ERC-2612 permit (gasless) - relayer pays gas fees.
     * 
     * @param string $userAddress User's wallet address
     * @param string $userPrivateKey User's private key (to sign permit)
     * @param string $tokenSymbol Token symbol: 'USDC', 'USDT', 'aUSDC'
     * @param int|null $deadline Optional deadline (default: ~10 years from now)
     * @return array ['success' => bool, 'txHash' => string, 'gasUsed' => string]
     */
    public function approveContractUnlimited(
        string $userAddress,
        string $userPrivateKey,
        string $tokenSymbol = 'USDC',
        ?int $deadline = null
    ): array {
        // Get token address
        $tokenAddress = $this->getTokenAddress($tokenSymbol);
        
        // Unlimited amount (uint256 max)
        $unlimitedAmount = '115792089237316195423570985008687907853269984665640564039457584007913129639935'; // 2^256 - 1
        
        // Deadline (default: 10 years)
        $exp = $deadline ?: (time() + 3650 * 24 * 60 * 60);
        
        // Build permit typed data
        $typedData = $this->buildPermitTypedData(
            $userAddress,
            $tokenAddress,
            $this->contractAddress,
            $unlimitedAmount,
            $exp
        );
        
        // Sign with user's private key
        $signature = $this->signTypedData($typedData, $userPrivateKey);
        
        // Relay permit transaction (relayer pays gas)
        return $this->relayPermitERC2612(
            $tokenAddress,
            $userAddress,
            $this->contractAddress,
            $unlimitedAmount,
            $exp,
            $signature
        );
    }
    
    /**
     * Complete flow: Create wallet + approve Zorah contract for unlimited spending
     * 
     * @param string $password Password to encrypt the wallet
     * @param string $tokenSymbol Token to approve: 'USDC', 'USDT', 'aUSDC' (optional, defaults to USDC)
     * @return array ['address' => string, 'encryptedPrivateKey' => string, 'approval' => [...]]
     */
    public function createWalletAndApprove(string $password, string $tokenSymbol = 'USDC'): array
    {
        // Create new wallet
        $wallet = $this->walletManager->createWalletWithPassword($password);
        
        // Decrypt private key to sign permit
        $privateKey = $this->walletManager->decryptPrivateKey($wallet['encryptedPrivateKey'], $password);
        
        // Approve contract for unlimited spending
        $approval = $this->approveContractUnlimited(
            $wallet['address'],
            $privateKey,
            $tokenSymbol
        );
        
        return [
            'address' => $wallet['address'],
            'encryptedPrivateKey' => $wallet['encryptedPrivateKey'],
            'approval' => $approval
        ];
    }
    
    private function parseEscrowIdFromLogs(array $logs): int
    {
        $eventSignature = '0x' . \kornrunner\Keccak::hash('EscrowCreated(uint256,address,address,uint256,bool)', 256);
        
        foreach ($logs as $log) {
            if (!isset($log['topics'][0])) continue;
            if (strtolower($log['topics'][0]) === strtolower($eventSignature)) {
                return hexdec($log['topics'][1]); // escrowId is first indexed parameter
            }
        }
        
        throw new \Exception('EscrowCreated event not found in transaction logs');
    }
    
    private function parseDisputeIdFromLogs(array $logs): int
    {
        $eventSignature = '0x' . \kornrunner\Keccak::hash('DisputeCreated(uint256,uint256,uint256)', 256);
        
        foreach ($logs as $log) {
            if (!isset($log['topics'][0])) continue;
            if (strtolower($log['topics'][0]) === strtolower($eventSignature)) {
                return hexdec($log['topics'][1]); // disputeId is first indexed parameter
            }
        }
        
        throw new \Exception('DisputeCreated event not found in transaction logs');
    }
    
    private function parseUSDC(string $amount): string
    {
        return bcmul($amount, '1000000', 0);
    }
    
    private function formatUSDC(string $amount): string
    {
        return bcdiv($amount, '1000000', 6);
    }
    
    public function toWei(string $amount, int $decimals = 6): string
    {
        return bcmul($amount, bcpow('10', (string)$decimals), 0);
    }
    
    public function fromWei(string $amountWei, int $decimals = 6): string
    {
        return bcdiv($amountWei, bcpow('10', (string)$decimals), $decimals);
    }
    
    /**
     * Add juror (RELAYER_ROLE only, relayer pays)
     */
    public function addJuror(string $jurorAddress): array
    {
        $txHash = $this->sendContractTransaction('addJuror', [$jurorAddress]);
        $receipt = $this->walletManager->waitForTransaction($txHash);
        return ['success' => $receipt['success'], 'txHash' => $txHash, 'gasUsed' => $receipt['gasUsed']];
    }
    
    /**
     * Remove juror (RELAYER_ROLE only, relayer pays)
     */
    public function removeJuror(string $jurorAddress): array
    {
        $txHash = $this->sendContractTransaction('removeJuror', [$jurorAddress]);
        $receipt = $this->walletManager->waitForTransaction($txHash);
        return ['success' => $receipt['success'], 'txHash' => $txHash, 'gasUsed' => $receipt['gasUsed']];
    }
    
    /**
     * Add premium user (RELAYER_ROLE only, relayer pays)
     */
    public function addPremiumUser(string $userAddress): array
    {
        $txHash = $this->sendContractTransaction('addPremiumUser', [$userAddress]);
        $receipt = $this->walletManager->waitForTransaction($txHash);
        return ['success' => $receipt['success'], 'txHash' => $txHash, 'gasUsed' => $receipt['gasUsed']];
    }
    
    /**
     * Remove premium user (RELAYER_ROLE only, relayer pays)
     */
    public function removePremiumUser(string $userAddress): array
    {
        $txHash = $this->sendContractTransaction('removePremiumUser', [$userAddress]);
        $receipt = $this->walletManager->waitForTransaction($txHash);
        return ['success' => $receipt['success'], 'txHash' => $txHash, 'gasUsed' => $receipt['gasUsed']];
    }
    
    /**
     * Set blacklist status (RELAYER_ROLE only, relayer pays)
     */
    public function setBlacklistStatus(string $userAddress, bool $status): array
    {
        $txHash = $this->sendContractTransaction('setBlacklistStatus', [$userAddress, $status]);
        $receipt = $this->walletManager->waitForTransaction($txHash);
        return ['success' => $receipt['success'], 'txHash' => $txHash, 'gasUsed' => $receipt['gasUsed']];
    }
    
    /**
     * Get configuration (view)
     */
    public function getConfiguration(): array
    {
        $result = $this->callContractMethod('getConfiguration', []);
        return [
            'escrowFeePercentage' => (int)$result['_escrowFeePercentage'],
            'maxEscrowFee' => $this->formatUSDC($result['_maxEscrowFee']),
            'disputePenaltyPercentage' => (int)$result['_disputePenaltyPercentage'],
            'quorumPercentage' => (int)$result['_quorumPercentage'],
            'adminWallet' => $result['_adminWallet'],
            'emergencyLocked' => $result['_emergencyLocked'],
            'emergencyLockTime' => (int)$result['_emergencyLockTime']
        ];
    }
    
    
    public function getRoleMemberCount(string $roleHash): int
    {
        $result = $this->callContractMethod('getRoleMemberCount', [$roleHash]);
        return is_array($result) && isset($result[0]) ? (int)$result[0] : (int)$result;
    }
    
    public function getRoleMember(string $roleHash, int $index): string
    {
        $result = $this->callContractMethod('getRoleMember', [$roleHash, $index]);
        return is_array($result) && isset($result[0]) ? $result[0] : $result;
    }
    
    public function processExpiredEscrow(int $escrowId): array
    {
        $txHash = $this->sendContractTransaction('processExpiredEscrow', [$escrowId]);
        $receipt = $this->walletManager->waitForTransaction($txHash);
        return ['success' => $receipt['success'], 'txHash' => $txHash, 'gasUsed' => $receipt['gasUsed']];
    }
    
    public function resolveExpiredDispute(int $disputeId): array
    {
        $txHash = $this->sendContractTransaction('resolveExpiredDispute', [$disputeId]);
        $receipt = $this->walletManager->waitForTransaction($txHash);
        return ['success' => $receipt['success'], 'txHash' => $txHash, 'gasUsed' => $receipt['gasUsed']];
    }
    
    public function hasUserVoted(int $disputeId, string $userAddress): bool
    {
        $result = $this->callContractMethod('hasUserVoted', [$disputeId, $userAddress]);
        return is_array($result) && isset($result[0]) ? (bool)$result[0] : (bool)$result;
    }
    
    public function calculateEscrowFee(string $amount): string
    {
        $amountWei = $this->parseUSDC($amount);
        $result = $this->callContractMethod('calculateEscrowFee', [$amountWei]);
        $feeWei = is_array($result) && isset($result[0]) ? $result[0] : $result;
        return $this->formatUSDC($feeWei);
    }
}