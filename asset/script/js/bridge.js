/**
 * Axelar EVM Bridge SDK for JavaScript
 * Complete production-ready bridge solution using ethers.js
 * 
 * @version 2.0.0
 * @requires ethers.js v5.x
 * @author Adyeye George
 * 
 */

// Compatibility helper for ethers v5/v6 parseEther differences
const parseEtherCompat = (value) => {
    if (typeof ethers === 'undefined') {
        throw new Error('ethers library not loaded');
    }
    if (ethers.parseEther) return ethers.parseEther(value);
    if (ethers.utils && ethers.utils.parseEther) return ethers.utils.parseEther(value);
    throw new Error('parseEther not available in current ethers version');
};

const formatEtherCompat = (value) => {
    // v6 accepts bigint; v5 expects BigNumber
    if (ethers.formatEther) return ethers.formatEther(value);
    if (ethers.utils && ethers.utils.formatEther) {
        const bn = (ethers.BigNumber ? ethers.BigNumber.from(value.toString()) : value);
        return ethers.utils.formatEther(bn);
    }
    throw new Error('formatEther not available');
};

const parseUnitsCompat = (value, decimals) => {
    if (ethers.parseUnits) return ethers.parseUnits(value, decimals);
    if (ethers.utils && ethers.utils.parseUnits) return ethers.utils.parseUnits(value, decimals);
    throw new Error('parseUnits not available');
};

const formatUnitsCompat = (value, decimals) => {
    if (ethers.formatUnits) return ethers.formatUnits(value, decimals);
    if (ethers.utils && ethers.utils.formatUnits) {
        const bn = (ethers.BigNumber ? ethers.BigNumber.from(value.toString()) : value);
        return ethers.utils.formatUnits(bn, decimals);
    }
    throw new Error('formatUnits not available');
};

// Provider compatibility (ethers v5 vs v6 UMD builds)
const createJsonRpcProvider = (rpcUrl) => {
    if (ethers.JsonRpcProvider) return new ethers.JsonRpcProvider(rpcUrl);
    if (ethers.providers && ethers.providers.JsonRpcProvider) return new ethers.providers.JsonRpcProvider(rpcUrl);
    throw new Error('JsonRpcProvider not available in this ethers build');
};

const createWeb3Provider = (raw) => {
    const injected = raw || (typeof window !== 'undefined' ? window.ethereum : null);
    if (!injected) {
        throw new Error('No injected wallet provider found');
    }
    if (ethers.BrowserProvider) return new ethers.BrowserProvider(injected);
    if (ethers.providers && ethers.providers.Web3Provider) return new ethers.providers.Web3Provider(injected);
    throw new Error('Browser/Web3 provider not available in this ethers build');
};

// Detect whether an incoming amount is already in base units (wei-like) or human units.
// Returns { amountHuman, amountWei, rawInput }
const resolveAmountCompat = (input, decimals) => {
    const tenPow = (d) => BigInt('1' + '0'.repeat(d));
    const toBigInt = (v) => {
        if (typeof v === 'bigint') return v;
        if (typeof v === 'number') return BigInt(Math.trunc(v));
        if (typeof v === 'string') return BigInt(v);
        throw new Error('Unsupported amount type');
    };

    // If it's bigint we treat as raw units directly.
    if (typeof input === 'bigint') {
        const amountWei = input;
        return {
            amountHuman: formatUnitsCompat(amountWei, decimals),
            amountWei,
            rawInput: true
        };
    }

    // Normalize strings
    if (typeof input === 'string' && /^\d+$/.test(input)) {
        // Heuristic: if length > decimals+2 assume raw units; or divisible by 10^decimals with no remainder
        const bi = toBigInt(input);
        const pow = tenPow(decimals);
        const isDivisible = (bi % pow) === 0n;
        if (input.length > decimals + 2 || isDivisible) {
            return {
                amountHuman: formatUnitsCompat(bi, decimals),
                amountWei: bi,
                rawInput: true
            };
        }
        // else treat as human number
        const amountWei = parseUnitsCompat(input, decimals);
        return {
            amountHuman: input,
            amountWei,
            rawInput: false
        };
    }

    // Plain number -> human units
    if (typeof input === 'number') {
        const amountWei = parseUnitsCompat(input.toString(), decimals);
        return {
            amountHuman: input.toString(),
            amountWei,
            rawInput: false
        };
    }

    throw new Error('Unsupported amount format');
};

class AxelarBridge {
    constructor(config = {}) {
        this.config = {
            environment: config.environment || 'mainnet',
            rpcUrls: config.rpcUrls || {},
            ...config
        };

        // Axelar Gateway & Gas Service addresses (same on all EVM chains)
        this.contracts = {
            mainnet: {
                gateway: '0x4F4495243837681061C4743b74B3eEdf548D56A5',
                gasService: '0x2d5d7d31F671F86C782533cc367F14109a082712'
            },
            testnet: {
                gateway: '0xe432150cce91c13a887f7D836923d5597adD8E31',
                gasService: '0xbE406F0189A0B4cf3A05C286473D23791Dd44Cc6'
            }
        };

        // Chain configuration
        this.chains = {
            ethereum: {
                id: 1,
                testnetId: 11155111,
                axelarName: 'Ethereum',
                nativeToken: 'ETH',
                coingeckoId: 'ethereum',
                decimals: 18,
                rpcUrl: 'https://eth.llamarpc.com',
                testnetRpc: 'https://ethereum-sepolia.publicnode.com'
            },
            bsc: {
                id: 56,
                testnetId: 97,
                axelarName: 'binance',
                nativeToken: 'BNB',
                coingeckoId: 'binancecoin',
                decimals: 18,
                rpcUrl: 'https://bsc-dataseed1.binance.org',
                testnetRpc: 'https://data-seed-prebsc-1-s1.binance.org:8545'
            },
            polygon: {
                id: 137,
                testnetId: 80002,
                axelarName: 'Polygon',
                nativeToken: 'MATIC',
                coingeckoId: 'matic-network',
                decimals: 18,
                rpcUrl: 'https://polygon-rpc.com',
                testnetRpc: 'https://rpc-amoy.polygon.technology'
            },
            avalanche: {
                id: 43114,
                testnetId: 43113,
                axelarName: 'Avalanche',
                nativeToken: 'AVAX',
                coingeckoId: 'avalanche-2',
                decimals: 18,
                rpcUrl: 'https://api.avax.network/ext/bc/C/rpc',
                testnetRpc: 'https://api.avax-test.network/ext/bc/C/rpc'
            },
            moonbeam: {
                id: 1284,
                testnetId: 1287,
                axelarName: 'Moonbeam',
                nativeToken: 'GLMR',
                coingeckoId: 'moonbeam',
                decimals: 18,
                rpcUrl: 'https://rpc.api.moonbeam.network',
                testnetRpc: 'https://rpc.api.moonbase.moonbeam.network'
            },
            arbitrum: {
                id: 42161,
                testnetId: 421614,
                axelarName: 'arbitrum',
                nativeToken: 'ETH',
                coingeckoId: 'ethereum',
                decimals: 18,
                rpcUrl: 'https://arb1.arbitrum.io/rpc',
                testnetRpc: 'https://sepolia-rollup.arbitrum.io/rpc'
            },
            optimism: {
                id: 10,
                testnetId: 11155420,
                axelarName: 'optimism',
                nativeToken: 'ETH',
                coingeckoId: 'ethereum',
                decimals: 18,
                rpcUrl: 'https://mainnet.optimism.io',
                testnetRpc: 'https://sepolia.optimism.io'
            },
            base: {
                id: 8453,
                testnetId: 84532,
                axelarName: 'base',
                nativeToken: 'ETH',
                coingeckoId: 'ethereum',
                decimals: 18,
                rpcUrl: 'https://mainnet.base.org',
                testnetRpc: 'https://sepolia.base.org'
            }
        };

        // Token addresses
        this.tokens = {
            mainnet: {
                USDC: {
                    ethereum: '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
                    polygon: '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359',
                    avalanche: '0xB97EF9Ef8734C71904D8002F8b6Bc66Dd9c48a6E',
                    bsc: '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d',
                    moonbeam: '0x931715FEE2d06333043d11F658C8CE934aC61D0c',
                    arbitrum: '0xaf88d065e77c8cC2239327C5EDb3A432268e5831',
                    optimism: '0x0b2C639c533813f4Aa9D7837CAf62653d097Ff85',
                    base: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913'
                },
                USDT: {
                    ethereum: '0xdAC17F958D2ee523a2206206994597C13D831ec7',
                    polygon: '0xc2132D05D31c914a87C6611C10748AEb04B58e8F',
                    avalanche: '0x9702230A8Ea53601f5cD2dc00fDBc13d4dF4A8c7',
                    bsc: '0x55d398326f99059fF775485246999027B3197955',
                    moonbeam: '0xeFAeeE334F0Fd1712f9a8cc375f427D9Cdd40d73',
                    arbitrum: '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9',
                    optimism: '0x94b008aA00579c1307B0EF2c499aD98a8ce58e58'
                }
            },
            testnet: {
                aUSDC: {
                    ethereum: '0x254d06f33bDc5b8ee05b2ea472107E300226659A',
                    polygon: '0x254d06f33bDc5b8ee05b2ea472107E300226659A',
                    avalanche: '0x57F1c63497AEe0bE305B8852b354CEc793da43bB',
                    moonbeam: '0xD1633F7Fb3d716643125d6415d4177bC36b7186b',
                    bsc: '0x254d06f33bDc5b8ee05b2ea472107E300226659A'
                }
            }
        };

        // ERC20 ABI
        this.erc20Abi = [
            'function approve(address spender, uint256 amount) returns (bool)',
            'function balanceOf(address owner) view returns (uint256)',
            'function decimals() view returns (uint8)',
            'function transfer(address to, uint256 amount) returns (bool)',
            'event Transfer(address indexed from, address indexed to, uint256 value)'
        ];

        // Initialize providers cache
        this.providers = {};
        this.signer = null;
        this.rawProvider = null; // selected EIP-1193 provider
    }

    /**
     * Change runtime environment and reset providers cache
     */
    setEnvironment(env) {
        const normalized = env === 'mainnet' ? 'mainnet' : 'testnet';
        if (this.config.environment !== normalized) {
            this.config.environment = normalized;
            this.providers = {}; // reset cached RPC providers to match new env
            console.log(`Environment switched to ${normalized}`);
        }
    }

    /**
     * Connect wallet using ethers.js
     * @returns {Promise<object>} Connected wallet info
     */
    async connectWallet(raw) {
        const injected = raw || (typeof window !== 'undefined' ? window.ethereum : null);
        if (!injected) {
            throw new Error('MetaMask or compatible wallet not found. Please install MetaMask.');
        }

        try {
            const provider = createWeb3Provider(injected);
            await provider.send('eth_requestAccounts', []);
            
            this.signer = await provider.getSigner();
            this.rawProvider = injected;
            const address = await this.signer.getAddress();
            
            // Force fresh network detection to avoid cached network issues
            let network;
            try {
                network = await provider.detectNetwork();
            } catch (e) {
                network = await provider.getNetwork();
            }
            
            console.log('Wallet connected:', address);
            
            return {
                address,
                chainId: Number(network.chainId),
                chainName: this.getChainNameById(Number(network.chainId)),
                provider,
                signer: this.signer
            };
        } catch (error) {
            console.error('Wallet connection failed:', error);
            throw new Error('Failed to connect wallet: ' + error.message);
        }
    }

    /**
     * Switch to specific chain
     * @param {string} chainName - Chain name (e.g., 'polygon')
     */
    async switchChain(chainName) {
        if (!this.chains[chainName]) {
            throw new Error(`Unsupported chain: ${chainName}`);
        }

        const chain = this.chains[chainName];
        const chainId = this.config.environment === 'mainnet' ? chain.id : chain.testnetId;
        const chainIdHex = '0x' + chainId.toString(16);

        try {
            const req = (this.rawProvider && this.rawProvider.request) ? this.rawProvider.request.bind(this.rawProvider) : (window.ethereum && window.ethereum.request ? window.ethereum.request.bind(window.ethereum) : null);
            if (!req) throw new Error('No wallet request function found');
            await req({
                method: 'wallet_switchEthereumChain',
                params: [{ chainId: chainIdHex }]
            });

            console.log(`Switched to ${chainName}`);
            return true;
        } catch (error) {
            // Chain not added to wallet
            if (error.code === 4902) {
                await this.addChainToWallet(chainName);
                return await this.switchChain(chainName);
            }
            throw error;
        }
    }

    /**
     * Add chain to wallet
     * @param {string} chainName - Chain name
     */
    async addChainToWallet(chainName) {
        const chain = this.chains[chainName];
        const isTestnet = this.config.environment === 'testnet';
        const chainId = isTestnet ? chain.testnetId : chain.id;
        const rpcUrl = isTestnet ? chain.testnetRpc : chain.rpcUrl;

        const req = (this.rawProvider && this.rawProvider.request) ? this.rawProvider.request.bind(this.rawProvider) : (window.ethereum && window.ethereum.request ? window.ethereum.request.bind(window.ethereum) : null);
        if (!req) throw new Error('No wallet request function found');
        await req({
            method: 'wallet_addEthereumChain',
            params: [{
                chainId: '0x' + chainId.toString(16),
                chainName: `${chain.axelarName}${isTestnet ? ' Testnet' : ''}`,
                nativeCurrency: {
                    name: chain.nativeToken,
                    symbol: chain.nativeToken,
                    decimals: chain.decimals
                },
                rpcUrls: [rpcUrl]
            }]
        });
    }

    /**
     * Calculate complete bridge fees with USD conversion
     * @param {string} sourceChain - Source chain name
     * @param {number} amount - Amount to bridge
     * @param {string} token - Token symbol (USDC or USDT)
     * @param {string} destinationChain - Destination chain (default: moonbeam)
     * @returns {Promise<object>} Complete fee breakdown
     */
    async calculateBridgeFees(sourceChain, amount, token = 'USDC', destinationChain = 'moonbeam') {
        console.log('Calculating bridge fees...', { sourceChain, amount, token });

        try {
            const decimalsForToken = token === 'USDC' || token === 'USDT' || token === 'aUSDC' ? 6 : 18;
            const { amountHuman, amountWei } = resolveAmountCompat(amount, decimalsForToken);
            // Use normalized human amount for display, raw wei for fee estimation.
            // Decide mode for fees: sendToken path does not require gas service
            const simpleMode = true; // default for our SDK; can be made configurable later
            let axelarGas = { gasFeeWei: '0', executionFee: '0', baseFee: '0' };
            if (!simpleMode) {
                axelarGas = await this.estimateAxelarGas(sourceChain, destinationChain, amountHuman, token);
            }

            // Token transfer fee estimation (for sendToken path). We attempt API first, fallback to heuristic.
            const transferFee = await this.estimateTransferTokenFee(sourceChain, destinationChain, token, amountHuman, decimalsForToken);

            // 2. Get current gas price on source chain
            const provider = this.getProvider(sourceChain);
            const feeData = await provider.getFeeData();
            const gasPriceRaw = feeData.gasPrice || feeData.maxFeePerGas || feeData.maxPriorityFeePerGas;
            if (!gasPriceRaw) {
                throw new Error('Unable to determine gas price from provider');
            }
            const gasPrice = (typeof gasPriceRaw === 'bigint') ? gasPriceRaw : BigInt(gasPriceRaw.toString());

            // 3. Calculate transaction gas costs
            const approveGas = 50000n;
            const bridgeGas = 150000n;
            const gasPaymentGas = simpleMode ? 0n : 100000n;
            const totalGasNeeded = approveGas + bridgeGas + gasPaymentGas;
            
            const sourceChainGasCost = totalGasNeeded * gasPrice;
            const sourceChainGasCostEther = formatEtherCompat(sourceChainGasCost);

            // 4. Get native token prices in USD
            const chainInfo = this.chains[sourceChain];
            const sourceNativePrice = await this.getNativeTokenPrice(sourceChain);

            // 5. Calculate costs in USD
            const sourceGasCostUSD = (parseFloat(sourceChainGasCostEther) * sourceNativePrice).toFixed(2);

            // 6. Calculate Axelar relayer fee in USD
            const axelarGasWei = BigInt(axelarGas.gasFeeWei || '0');
            const axelarGasEther = formatEtherCompat(axelarGasWei);
            const axelarGasUSD = (parseFloat(axelarGasEther) * sourceNativePrice).toFixed(2);

            // 7. Calculate total fees
            const totalGasWei = sourceChainGasCost + axelarGasWei;
            const totalGasEther = formatEtherCompat(totalGasWei);
            const totalFeeUSD = (parseFloat(sourceGasCostUSD) + parseFloat(axelarGasUSD)).toFixed(2);

            // 8. Net amount received (no token deduction for GMP)
            // Net received after token transfer fee
            const displayAmount = amountHuman;
            const transferFeeHuman = transferFee.amountHuman;
            const netReceived = Math.max(0, parseFloat(displayAmount) - parseFloat(transferFeeHuman)).toString();

            const result = {
                sourceChain,
                destinationChain,
                amount: displayAmount,
                token,
                fees: {
                    sourceChainGas: {
                        wei: sourceChainGasCost.toString(),
                        ether: sourceChainGasCostEther,
                        usd: sourceGasCostUSD,
                        nativeToken: chainInfo.nativeToken,
                        breakdown: {
                            approveGas: approveGas.toString(),
                            bridgeGas: bridgeGas.toString(),
                            gasPaymentGas: gasPaymentGas.toString()
                        }
                    },
                    axelarGas: {
                        wei: axelarGasWei.toString(),
                        ether: axelarGasEther,
                        usd: axelarGasUSD,
                        nativeToken: chainInfo.nativeToken
                    },
                    total: {
                        wei: totalGasWei.toString(),
                        ether: totalGasEther,
                        usd: totalFeeUSD,
                        nativeToken: chainInfo.nativeToken
                    }
                },
                transferFee: {
                    token,
                    amountHuman: transferFeeHuman,
                    amountWei: transferFee.amountWei.toString(),
                    amountFormatted: `${transferFeeHuman} ${token}`
                },
                netReceived,
                nativeTokenPrice: sourceNativePrice,
                estimatedTime: '2-5 minutes',
                gasPaymentRequired: simpleMode
                    ? { amount: '0', token: chainInfo.nativeToken, amountFormatted: `0 ${chainInfo.nativeToken}` }
                    : {
                        amount: axelarGasWei.toString(),
                        token: chainInfo.nativeToken,
                        amountFormatted: `${axelarGasEther} ${chainInfo.nativeToken}`
                    }
            };

            console.log('Fees calculated:', result);
            return result;

        } catch (error) {
            console.error('Fee calculation failed:', error);
            throw new Error('Failed to calculate bridge fees: ' + error.message);
        }
    }

    /**
     * Estimate token transfer fee for sendToken path.
     * Attempts Axelar scan API; falls back to heuristic mapping.
     */
    async estimateTransferTokenFee(sourceChain, destinationChain, token, amountHuman, decimals) {
        // Attempt API (best-effort; not official SDK)
        const baseUrl = this.config.environment === 'mainnet'
            ? 'https://api.axelarscan.io'
            : 'https://testnet.api.axelarscan.io';
        const sourceAxelar = this.chains[sourceChain].axelarName;
        const destAxelar = this.chains[destinationChain].axelarName;
        let feeHuman = '0';
        try {
            const params = new URLSearchParams({
                sourceChain: sourceAxelar,
                destinationChain: destAxelar,
                symbol: token,
                amount: amountHuman.toString()
            });
            // Guessing endpoint name based on explorer usage; may change.
            const apiUrlCandidates = [
                `${baseUrl}/api/v2/transferFee?${params.toString()}`,
                `${baseUrl}/api/transferFee?${params.toString()}`
            ];
            for (const url of apiUrlCandidates) {
                const res = await fetch(url);
                if (res.ok) {
                    const data = await res.json();
                    // Attempt common keys
                    feeHuman = data.fee || data.transferFee || data.amount || '0';
                    if (typeof feeHuman === 'number') feeHuman = feeHuman.toString();
                    break;
                }
            }
        } catch (e) {
            console.warn('Transfer fee API lookup failed:', e.message);
        }

        // Heuristic fallback: testnet aUSDC fee observed ~0.2 for amount 1 (20%)
        if (feeHuman === '0') {
            if (this.config.environment === 'testnet' && token === 'aUSDC') {
                // Use proportional 20% fee (simplistic). Scale linearly with amount.
                feeHuman = (parseFloat(amountHuman) * 0.20).toString();
            }
        }

        // Bound fee not to exceed amount
        const feeFloat = Math.min(parseFloat(amountHuman), parseFloat(feeHuman));
        const feeHumanFinal = feeFloat.toString();
        const feeWei = parseUnitsCompat(feeHumanFinal, decimals);
        return { amountHuman: feeHumanFinal, amountWei: feeWei };
    }

    /**
     * Get gas requirements for user display
     * @param {string} sourceChain - Source chain name
     * @param {number} amount - Amount to bridge
     * @param {string} token - Token symbol
     * @returns {Promise<object>} Gas requirements
     */
    async getGasRequirements(sourceChain, amount, token = 'USDC') {
        const chainInfo = this.chains[sourceChain];
        const fees = await this.calculateBridgeFees(sourceChain, amount, token);

        return {
            chainName: chainInfo.axelarName,
            nativeToken: chainInfo.nativeToken,
            requiredAmount: fees.fees.total.ether,
            requiredAmountWei: fees.fees.total.wei,
            usdValue: fees.fees.total.usd,
            message: `You need ${fees.fees.total.ether} ${chainInfo.nativeToken} ($${fees.fees.total.usd}) in your wallet on ${chainInfo.axelarName} to pay for gas fees`,
            breakdown: {
                'Transaction gas on source chain': `$${fees.fees.sourceChainGas.usd}`,
                'Axelar relayer fee (cross-chain)': `$${fees.fees.axelarGas.usd}`
            }
        };
    }

    /**
     * Execute bridge transaction
     * @param {string} sourceChain - Source chain name
     * @param {string} destinationAddress - Destination address on Moonbeam
     * @param {number} amount - Amount to bridge
     * @param {string} token - Token symbol
     * @returns {Promise<object>} Transaction result
     */
    async executeBridge(sourceChain, destinationAddress, amount, token) {
        console.log('Executing bridge...', { sourceChain, destinationAddress, amount, token });

        try {
            // 1. Validate inputs
            this.validateBridgeParams(sourceChain, destinationAddress, amount, token);

            // Environment/token sanity: prevent testnet token on mainnet source
            const isTestnetEnv = this.config.environment === 'testnet';
            if (token === 'aUSDC' && !isTestnetEnv) {
                throw new Error('aUSDC is a testnet token. Initialize AxelarBridge with environment: "testnet" and connect to Sepolia (Ethereum testnet).');
            }

            // 2. Ensure wallet is connected
            if (!this.signer) {
                throw new Error('Wallet not connected. Please connect your wallet first.');
            }

            // 3. Get current network and verify it matches source chain
            const provider = this.signer.provider;
            
            // Force refresh network to avoid cached network issues
            let network;
            try {
                // Detect network change by calling getNetwork with fresh state
                network = await provider.detectNetwork();
            } catch (e) {
                // Fallback to regular getNetwork if detectNetwork not available
                network = await provider.getNetwork();
            }
            
            const expectedChainId = this.config.environment === 'mainnet' 
                ? this.chains[sourceChain].id 
                : this.chains[sourceChain].testnetId;

            if (Number(network.chainId) !== expectedChainId) {
                throw new Error(`Please switch to ${this.chains[sourceChain].axelarName} network (Expected chainId: ${expectedChainId}, Got: ${network.chainId})`);
            }

            // 4. Calculate fees (simple transfer path may not need gas payment step)
            const fees = await this.calculateBridgeFees(sourceChain, amount, token);

            // 5. Get addresses
            const fromAddress = await this.signer.getAddress();
            const tokenAddress = this.getTokenAddress(sourceChain, token);
            const gatewayAddress = this.getGatewayAddress();
            const gasServiceAddress = this.getGasServiceAddress();

            // 6. Check balances
            await this.checkBalances(sourceChain, fromAddress, tokenAddress, amount, fees.fees.total.wei);

            // 7. Determine bridging mode
            // If destination is EOA and user is not intentionally doing a contract call, use simple sendToken
            const useSimpleTransfer = this.shouldUseSimpleTransfer(destinationAddress);

            // 8. Execute transactions
            const transactions = {};

            // Step 1: Approve token
            console.log('Step 1/3: Approving token...');
            const tokenContract = new ethers.Contract(tokenAddress, this.erc20Abi, this.signer);
            const decimals = token === 'USDC' || token === 'USDT' ? 6 : 18;
            const { amountWei } = resolveAmountCompat(amount, decimals);

            const approveTx = await tokenContract.approve(gatewayAddress, amountWei);
            transactions.approve = approveTx.hash;
            console.log('Approve TX:', approveTx.hash);
            
            await approveTx.wait();
            console.log('✓ Token approved');

            let bridgeTx;
            if (useSimpleTransfer) {
                console.log('Simple transfer path selected (sendToken).');
                console.log('Step 2/2: Bridging tokens with sendToken...');
                bridgeTx = await this.sendToken(
                    gatewayAddress,
                    'moonbeam',
                    destinationAddress,
                    token,
                    amount
                );
            } else {
                // Contract call with token path
                console.log('Contract call path selected (callContractWithToken).');
                console.log('Step 2/3: Paying gas to Axelar...');
                const payload = this.encodePayload(destinationAddress);
                const gasPaymentTx = await this.payGasForContractCallWithToken(
                    gasServiceAddress,
                    gatewayAddress,
                    'moonbeam',
                    destinationAddress,
                    payload,
                    token,
                    amount,
                    fees.fees.axelarGas.wei
                );
                transactions.gasPayment = gasPaymentTx.hash;
                console.log('Gas payment TX:', gasPaymentTx.hash);
                await gasPaymentTx.wait();
                console.log('✓ Gas paid');

                console.log('Step 3/3: Bridging tokens (contract call)...');
                bridgeTx = await this.callContractWithToken(
                    gatewayAddress,
                    'moonbeam',
                    destinationAddress,
                    payload,
                    token,
                    amount
                );
            }
            transactions.bridge = bridgeTx.hash;
            console.log('Bridge TX:', bridgeTx.hash);

            const receipt = await bridgeTx.wait();
            console.log('✓ Bridge transaction confirmed');

            const result = {
                success: true,
                transactions,
                mainTxHash: bridgeTx.hash,
                sourceChain,
                destinationChain: 'moonbeam',
                destinationAddress,
                amount,
                token,
                fees,
                mode: useSimpleTransfer ? 'sendToken' : 'callContractWithToken',
                blockNumber: receipt.blockNumber,
                estimatedArrival: new Date(Date.now() + 5 * 60 * 1000).toISOString(),
                trackingUrl: `https://${this.config.environment === 'testnet' ? 'testnet.' : ''}axelarscan.io/gmp/${bridgeTx.hash}`
            };

            console.log('Bridge executed successfully:', result);
            return result;

        } catch (error) {
            console.error('Bridge execution failed:', error);
            throw new Error('Bridge execution failed: ' + error.message);
        }
    }

    /**
     * Monitor deposits to a specific address
     * @param {string} chain - Chain name
     * @param {string} address - Address to monitor
     * @param {string} token - Token symbol
     * @param {number} fromBlock - Starting block (0 = last 1000 blocks)
     * @returns {Promise<Array>} List of deposits
     */
    async monitorDeposits(chain, address, token = 'USDC', fromBlock = 0) {
        console.log('Monitoring deposits...', { chain, address, token });

        try {
            const provider = this.getProvider(chain);
            const tokenAddress = this.getTokenAddress(chain, token);

            // Get latest block
            const latestBlock = await provider.getBlockNumber();
            const startBlock = fromBlock > 0 ? fromBlock : Math.max(0, latestBlock - 1000);

            // ERC20 Transfer event
            const tokenContract = new ethers.Contract(tokenAddress, this.erc20Abi, provider);
            const filter = tokenContract.filters.Transfer(null, address);

            // Get logs
            const logs = await provider.getLogs({
                ...filter,
                fromBlock: startBlock,
                toBlock: 'latest'
            });

            // Parse deposits
            const deposits = [];
            for (const log of logs) {
                const parsed = tokenContract.interface.parseLog(log);
                const decimals = token === 'USDC' || token === 'USDT' ? 6 : 18;
                const amount = formatUnitsCompat(parsed.args.value, decimals);

                const block = await provider.getBlock(log.blockNumber);

                deposits.push({
                    txHash: log.transactionHash,
                    blockNumber: log.blockNumber,
                    amount: parseFloat(amount),
                    amountRaw: parsed.args.value.toString(),
                    token,
                    from: parsed.args.from,
                    to: parsed.args.to,
                    timestamp: block.timestamp
                });
            }

            console.log(`Found ${deposits.length} deposit(s)`);
            return deposits;

        } catch (error) {
            console.error('Deposit monitoring failed:', error);
            throw new Error('Deposit monitoring failed: ' + error.message);
        }
    }

    /**
     * Get transaction status from Axelar network
     * @param {string} txHash - Transaction hash
     * @param {string} sourceChain - Source chain name
     * @returns {Promise<object>} Status information
     */
    async getTransactionStatus(txHash, sourceChain) {
        const baseUrl = this.config.environment === 'mainnet'
            ? 'https://api.axelarscan.io'
            : 'https://testnet.api.axelarscan.io';
        const axelarChainName = this.chains[sourceChain]?.axelarName || sourceChain;

        // 1) Try GMP status (for contract calls)
        try {
            const gmpUrl = `${baseUrl}/api/v2/gmp/${txHash}?sourceChain=${axelarChainName}`;
            const resp = await fetch(gmpUrl);
            if (resp.ok) {
                const data = await resp.json();
                if (data && (data.status || data.executed || data.approved)) {
                    return {
                        txHash,
                        status: data.status || 'unknown',
                        sourceChain,
                        destinationChain: data.call?.returnValues?.destinationChain || 'moonbeam',
                        amount: data.call?.returnValues?.amount || '0',
                        token: data.call?.returnValues?.symbol || 'USDC',
                        approved: Boolean(data.approved),
                        executed: Boolean(data.executed),
                        error: data.error || null,
                        timeSpent: data.time_spent || null,
                        confirmations: data.confirmation || []
                    };
                }
            }
        } catch (e) {
            console.warn('GMP status lookup failed:', e.message);
        }

        // 2) Try Transfer status (for sendToken path)
        const candidates = [
            `${baseUrl}/api/v2/transfers/${txHash}`,
            `${baseUrl}/api/transfer/${txHash}`,
            `${baseUrl}/api/v2/transfer/${txHash}`
        ];
        for (const url of candidates) {
            try {
                const r = await fetch(url);
                if (!r.ok) continue;
                const d = await r.json();
                // Normalize fields commonly shown on explorer
                const status = d.status || d.state || 'unknown';
                const executed = /completed|minted|received|success/i.test(status);
                const approved = /approved|confirmed|sent|initiated/i.test(status) || executed;
                const destChain = d.destinationChain || d.to_chain || 'moonbeam';
                const amount = d.amount || d.transfer?.amount || '0';
                const symbol = d.symbol || d.asset || 'USDC';
                return {
                    txHash,
                    status,
                    sourceChain,
                    destinationChain: destChain,
                    amount: amount?.toString?.() || String(amount),
                    token: symbol,
                    approved,
                    executed,
                    error: d.error || null,
                    timeSpent: d.time_spent || null,
                    confirmations: d.confirmation || []
                };
            } catch (e) {
                console.warn('Transfer status lookup failed for', url, e.message);
            }
        }

        throw new Error('Failed to get transaction status');
    }

    // ==================== PRIVATE HELPER METHODS ====================

    /**
     * Get provider for chain
     */
    getProvider(chain) {
        if (!this.providers[chain]) {
            const chainInfo = this.chains[chain];
            const rpcUrl = this.config.rpcUrls[chain] ||
                (this.config.environment === 'mainnet' ? chainInfo.rpcUrl : chainInfo.testnetRpc);
            this.providers[chain] = createJsonRpcProvider(rpcUrl);
        }
        return this.providers[chain];
    }

    /**
     * Estimate Axelar gas fees
     */
    async estimateAxelarGas(sourceChain, destinationChain, amount, token) {
        try {
            const baseUrl = this.config.environment === 'mainnet'
                ? 'https://api.axelarscan.io'
                : 'https://testnet.api.axelarscan.io';

            const decimals = token === 'USDC' || token === 'USDT' ? 6 : 18;
            const { amountWei } = resolveAmountCompat(amount, decimals);

            const params = new URLSearchParams({
                sourceChain: this.chains[sourceChain].axelarName,
                destinationChain: this.chains[destinationChain].axelarName,
                sourceTokenSymbol: token,
                amount: amountWei.toString(),
                gasLimit: '400000',
                gasMultiplier: '1.3'
            });

            const response = await fetch(`${baseUrl}/api/estimateGasFee?${params}`);
            
            if (response.ok) {
                const data = await response.json();
                return {
                    gasFeeWei: data.gasEstimate || this.getFallbackGasEstimate(sourceChain),
                    executionFee: data.executionFee || '0',
                    baseFee: data.baseFee || '0'
                };
            }
        } catch (error) {
            console.warn('Axelar API failed, using fallback estimate:', error.message);
        }

        return { gasFeeWei: this.getFallbackGasEstimate(sourceChain) };
    }

    /**
     * Fallback gas estimates
     */
    getFallbackGasEstimate(chain) {
        const estimates = {
            ethereum: parseEtherCompat('0.003'),
            polygon: parseEtherCompat('0.5'),
            bsc: parseEtherCompat('0.002'),
            avalanche: parseEtherCompat('0.05'),
            moonbeam: parseEtherCompat('0.1'),
            arbitrum: parseEtherCompat('0.0005'),
            optimism: parseEtherCompat('0.0005'),
            base: parseEtherCompat('0.0005')
        };
        return (estimates[chain] || parseEtherCompat('0.05')).toString();
    }

    /**
     * Get native token price in USD
     */
    async getNativeTokenPrice(chain) {
        const chainInfo = this.chains[chain];
        const coingeckoId = chainInfo.coingeckoId;

        try {
            const response = await fetch(
                `https://api.coingecko.com/api/v3/simple/price?ids=${coingeckoId}&vs_currencies=usd`
            );

            if (response.ok) {
                const data = await response.json();
                const price = data[coingeckoId]?.usd;
                if (price) {
                    return price;
                }
            }
        } catch (error) {
            console.warn('CoinGecko API failed, using fallback price:', error.message);
        }

        // Fallback prices
        const fallbackPrices = {
            ethereum: 2500,
            binancecoin: 300,
            'matic-network': 0.8,
            'avalanche-2': 30,
            moonbeam: 0.3
        };

        return fallbackPrices[coingeckoId] || 1;
    }

    /**
     * Pay gas for contract call with token
     */
    async payGasForContractCallWithToken(
        gasService,
        gateway,
        destinationChain,
        destinationAddress,
        payload,
        symbol,
        amount,
        gasPaymentWei
    ) {
        const gasServiceContract = new ethers.Contract(
            gasService,
            [
                'function payNativeGasForContractCallWithToken(address sender, string destinationChain, string destinationAddress, bytes payload, string symbol, uint256 amount, address refundAddress) payable'
            ],
            this.signer
        );

        const decimals = symbol === 'USDC' || symbol === 'USDT' ? 6 : 18;
        const { amountWei } = resolveAmountCompat(amount, decimals);
        const axelarDestChain = this.chains[destinationChain].axelarName;
        const fromAddress = await this.signer.getAddress();

        return await gasServiceContract.payNativeGasForContractCallWithToken(
            gateway,
            axelarDestChain,
            destinationAddress,
            payload,
            symbol,
            amountWei,
            fromAddress,
            { value: BigInt(gasPaymentWei) }
        );
    }

    /**
     * Call Axelar Gateway to bridge tokens
     */
    async callContractWithToken(
        gateway,
        destinationChain,
        destinationAddress,
        payload,
        symbol,
        amount
    ) {
        const gatewayContract = new ethers.Contract(
            gateway,
            [
                'function callContractWithToken(string destinationChain, string destinationContractAddress, bytes payload, string symbol, uint256 amount)'
            ],
            this.signer
        );

        const decimals = symbol === 'USDC' || symbol === 'USDT' ? 6 : 18;
        const { amountWei } = resolveAmountCompat(amount, decimals);
        const axelarDestChain = this.chains[destinationChain].axelarName;

        return await gatewayContract.callContractWithToken(
            axelarDestChain,
            destinationAddress,
            payload,
            symbol,
            amountWei
        );
    }

    /**
     * Simple token bridge using sendToken (EOA transfers)
     */
    async sendToken(
        gateway,
        destinationChain,
        destinationAddress,
        symbol,
        amount
    ) {
        const gatewayContract = new ethers.Contract(
            gateway,
            [
                'function sendToken(string destinationChain, string destinationAddress, string symbol, uint256 amount)'
            ],
            this.signer
        );

        const decimals = symbol === 'USDC' || symbol === 'USDT' || symbol === 'aUSDC' ? 6 : 18;
        const { amountWei } = resolveAmountCompat(amount, decimals);
        const axelarDestChain = this.chains[destinationChain].axelarName;

        // Provide manual gas limit if estimate fails
        try {
            return await gatewayContract.sendToken(
                axelarDestChain,
                destinationAddress,
                symbol,
                amountWei
            );
        } catch (e) {
            console.warn('sendToken gas estimation failed, retrying with manual gasLimit', e.message);
            return await gatewayContract.sendToken(
                axelarDestChain,
                destinationAddress,
                symbol,
                amountWei,
                { gasLimit: 800000 }
            );
        }
    }

    /**
     * Decide whether to use simple sendToken path
     */
    shouldUseSimpleTransfer(destinationAddress) {
        // Heuristic: if user passes their own EOA (no contract payload needed)
        // and no explicit override flag, use simple path.
        // Could be extended to detect contracts via provider.getCode for current chain.
        return true; // default to simple path for current implementation
    }

    /**
     * Check if user has sufficient balances
     */
    async checkBalances(chain, address, tokenAddress, amount, gasRequiredWei) {
        const provider = this.getProvider(chain);
        const chainInfo = this.chains[chain];

        // Check native token balance for gas
        const nativeBalance = await provider.getBalance(address);

        if (nativeBalance < BigInt(gasRequiredWei)) {
            const required = formatEtherCompat(gasRequiredWei);
            const has = formatEtherCompat(nativeBalance);
            throw new Error(
                `Insufficient ${chainInfo.nativeToken} for gas. Required: ${required}, Have: ${has}`
            );
        }

        // Check token balance
        const tokenContract = new ethers.Contract(tokenAddress, this.erc20Abi, provider);
        const tokenBalance = await tokenContract.balanceOf(address);

        const decimals = 6; // USDC/USDT/aUSDC
        const { amountWei: requiredTokenWei } = resolveAmountCompat(amount, decimals);

        if (tokenBalance < requiredTokenWei) {
            const has = formatUnitsCompat(tokenBalance, decimals);
            throw new Error(
                `Insufficient token balance. Required: ${amount}, Have: ${has}`
            );
        }

        console.log('Balance check passed', {
            address,
            nativeBalance: formatEtherCompat(nativeBalance),
            tokenBalance: formatUnitsCompat(tokenBalance, decimals)
        });
    }

    /**
     * Encode payload for destination contract
     */
    encodePayload(destinationAddress) {
        // ABI-encode the destination address for more flexible decoding downstream
        const coder = (ethers.AbiCoder ? new ethers.AbiCoder() : (ethers.utils && ethers.utils.AbiCoder ? new ethers.utils.AbiCoder() : null));
        if (!coder) {
            throw new Error('AbiCoder unavailable in current ethers build');
        }
        return coder.encode(['address'], [destinationAddress]);
    }

    /**
     * Validate bridge parameters
     */
    validateBridgeParams(sourceChain, destinationAddress, amount, token) {
        if (!this.chains[sourceChain]) {
            throw new Error(`Unsupported source chain: ${sourceChain}`);
        }

        if (!(ethers.isAddress ? ethers.isAddress(destinationAddress) : (ethers.utils && ethers.utils.isAddress ? ethers.utils.isAddress(destinationAddress) : false))) {
            throw new Error('Invalid destination address format');
        }

        if (amount <= 0) {
            throw new Error('Amount must be greater than 0');
        }

        const allowedMainnet = ['USDC', 'USDT'];
        const allowedTestnet = ['aUSDC'];
        const isTestnet = this.config.environment === 'testnet';
        const allowed = isTestnet ? allowedTestnet.concat(allowedMainnet) : allowedMainnet;
        if (!allowed.includes(token)) {
            throw new Error(`Unsupported token: ${token} (allowed: ${allowed.join(', ')})`);
        }

        const tokenAddress = this.getTokenAddress(sourceChain, token);
        if (!tokenAddress) {
            throw new Error(`Token ${token} not available on ${sourceChain}`);
        }
    }

    /**
     * Get token contract address
     */
    getTokenAddress(chain, token) {
        return this.tokens[this.config.environment][token]?.[chain] || null;
    }

    /**
     * Get Axelar Gateway address
     */
    getGatewayAddress() {
        return this.contracts[this.config.environment].gateway;
    }

    /**
     * Get Axelar Gas Service address
     */
    getGasServiceAddress() {
        return this.contracts[this.config.environment].gasService;
    }

    /**
     * Get chain name by chain ID
     */
    getChainNameById(chainId) {
        for (const [name, info] of Object.entries(this.chains)) {
            if (info.id === chainId || info.testnetId === chainId) {
                return name;
            }
        }
        return 'unknown';
    }

    /**
     * Get user-friendly chain info
     */
    getChainInfo(chainName) {
        return this.chains[chainName] || null;
    }

    /**
     * Get supported chains
     */
    getSupportedChains() {
        return Object.keys(this.chains);
    }

    /**
     * Get supported tokens
     */
    getSupportedTokens() {
        return Object.keys(this.tokens[this.config.environment]);
    }

    /**
     * Get blockchain explorer URL for a transaction
     * @param {string} chainName - Chain name (ethereum, polygon, etc.)
     * @param {string} txHash - Transaction hash
     * @returns {string} Explorer URL
     */
    getExplorerUrl(chainName, txHash) {
        const isTestnet = this.config.environment === 'testnet';
        const explorers = {
            ethereum: isTestnet ? 'https://sepolia.etherscan.io' : 'https://etherscan.io',
            polygon: isTestnet ? 'https://amoy.polygonscan.com' : 'https://polygonscan.com',
            avalanche: isTestnet ? 'https://testnet.snowtrace.io' : 'https://snowtrace.io',
            bsc: isTestnet ? 'https://testnet.bscscan.com' : 'https://bscscan.com',
            moonbeam: isTestnet ? 'https://moonbase.moonscan.io' : 'https://moonscan.io',
            arbitrum: isTestnet ? 'https://sepolia.arbiscan.io' : 'https://arbiscan.io',
            optimism: isTestnet ? 'https://sepolia-optimism.etherscan.io' : 'https://optimistic.etherscan.io',
            base: isTestnet ? 'https://sepolia.basescan.org' : 'https://basescan.org'
        };
        
        const baseUrl = explorers[chainName] || explorers.ethereum;
        return `${baseUrl}/tx/${txHash}`;
    }

    /**
     * Listen for wallet events
     */
    onAccountsChanged(callback) {
        const prov = this.rawProvider || (typeof window !== 'undefined' ? window.ethereum : null);
        if (prov && prov.on) {
            prov.on('accountsChanged', callback);
        }
    }

    /**
     * Listen for chain changed events
     */
    onChainChanged(callback) {
        const prov = this.rawProvider || (typeof window !== 'undefined' ? window.ethereum : null);
        if (prov && prov.on) {
            prov.on('chainChanged', callback);
        }
    }

    /**
     * Disconnect wallet
     */
    disconnect() {
        this.signer = null;
        this.rawProvider = null;
        console.log('Wallet disconnected');
    }

    /**
     * Detect available wallets (basic detection). Returns an array of wallet descriptors.
     * This keeps compatibility with the Receive modal wallet list.
     */
    async detectWallets() {
        const found = [];
        if (typeof window === 'undefined') return found;

        const nameFromFlags = (prov) => {
            if (!prov) return 'Injected Wallet';
            if (prov.isMetaMask) return 'MetaMask';
            if (prov.isCoinbaseWallet) return 'Coinbase Wallet';
            if (prov.isBraveWallet) return 'Brave Wallet';
            if (prov.isRabby) return 'Rabby Wallet';
            if (prov.isOKXWallet || prov.isOkxWallet) return 'OKX Wallet';
            if (prov.isBitKeep || prov.isBitget) return 'Bitget Wallet';
            if (prov.isTrust || prov.isTrustWallet) return 'Trust Wallet';
            if (prov.isSubWallet) return 'SubWallet';
            return 'Injected Wallet';
        };

        // Collect from window.ethereum.providers (multiple injected)
        const seenByRef = new Set();
        const seenByKey = new Set();
        const keyFor = (prov) => {
            if (!prov) return 'unknown';
            if (prov.isSubWallet) return 'subwallet';
            if (prov.isMetaMask) return 'metamask';
            if (prov.isCoinbaseWallet) return 'coinbase';
            if (prov.isBraveWallet) return 'brave';
            if (prov.isRabby) return 'rabby';
            if (prov.isOKXWallet || prov.isOkxWallet) return 'okx';
            if (prov.isBitKeep || prov.isBitget) return 'bitget';
            if (prov.isTrust || prov.isTrustWallet) return 'trust';
            if (prov.rdns) return String(prov.rdns).toLowerCase();
            return nameFromFlags(prov).toLowerCase();
        };

        const pushProvider = (prov) => {
            if (!prov) return;
            // Avoid duplicates by reference and brand key
            if (seenByRef.has(prov)) return;
            const idKey = keyFor(prov);
            if (seenByKey.has(idKey)) return;
            seenByRef.add(prov);
            seenByKey.add(idKey);
            found.push({
                info: {
                    name: nameFromFlags(prov),
                    uuid: (prov.id || prov.uuid || prov.rdns || nameFromFlags(prov)).toString(),
                    rdns: prov.rdns || 'injected'
                },
                type: 'eip1193',
                provider: prov
            });
        };

        const eth = window.ethereum;
        if (eth && Array.isArray(eth.providers)) {
            eth.providers.forEach(pushProvider);
        } else if (eth) {
            pushProvider(eth);
        }

        // EIP-6963 discovery (best effort): listen briefly for announcements
        const eip6963Providers = [];
        const handler = (event) => {
            try {
                const prov = event.detail?.provider;
                if (prov) eip6963Providers.push(prov);
            } catch {}
        };
        try { window.addEventListener('eip6963:announceProvider', handler); } catch {}
        try { window.dispatchEvent(new Event('eip6963:requestProvider')); } catch {}
        await new Promise(res => setTimeout(res, 150));
        try { window.removeEventListener('eip6963:announceProvider', handler); } catch {}
        eip6963Providers.forEach(pushProvider);

        // SubWallet specific globals (may be same ref; key-based dedupe handles it)
        if (window.SubWallet && window.SubWallet?.ethereum) pushProvider(window.SubWallet.ethereum);

        return found;
    }
}

// Export for use in browser
if (typeof window !== 'undefined') {
    window.AxelarBridge = AxelarBridge;
}

// Export for Node.js/module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AxelarBridge;
}