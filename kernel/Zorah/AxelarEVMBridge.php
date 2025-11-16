<?php
namespace Manomite\Zorah;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Web3\Web3;
use Web3\Contract;
use Web3\Utils;
use Web3\Providers\HttpProvider;
use Web3\RequestManagers\HttpRequestManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Complete Production-Ready Axelar Bridge SDK for EVM Chains
 * 
 * Supports bridging USDC/USDT from any EVM chain to Moonbeam
 * Features:
 * - Complete fee calculation with USD conversion
 * - Gas fee estimation and payment
 * - Real deposit monitoring
 * - Transaction signing and broadcasting
 * - Status tracking and recovery
 * - Mainnet and Testnet support
 * 
 * @package App\Bridge
 * @version 2.0.0
 * @author Your Name
 */
class AxelarEVMBridge
{
    private Client $httpClient;
    private LoggerInterface $logger;
    private array $config;
    private string $environment;
    
    // Axelar Gateway & Gas Service Addresses (Same on all EVM chains)
    private const MAINNET_GATEWAY = '0x4F4495243837681061C4743b74B3eEdf548D56A5';
    private const MAINNET_GAS_SERVICE = '0x2d5d7d31F671F86C782533cc367F14109a082712';
    
    private const TESTNET_GATEWAY = '0xe432150cce91c13a887f7D836923d5597adD8E31';
    private const TESTNET_GAS_SERVICE = '0xbE406F0189A0B4cf3A05C286473D23791Dd44Cc6';
    
    // Supported EVM chains with configuration
    private const CHAINS = [
        'ethereum' => [
            'id' => 1,
            'testnetId' => 11155111,
            'axelarName' => 'Ethereum',
            'nativeToken' => 'ETH',
            'coingeckoId' => 'ethereum',
            'decimals' => 18
        ],
        'bsc' => [
            'id' => 56,
            'testnetId' => 97,
            'axelarName' => 'binance',
            'nativeToken' => 'BNB',
            'coingeckoId' => 'binancecoin',
            'decimals' => 18
        ],
        'polygon' => [
            'id' => 137,
            'testnetId' => 80002,
            'axelarName' => 'Polygon',
            'nativeToken' => 'MATIC',
            'coingeckoId' => 'matic-network',
            'decimals' => 18
        ],
        'avalanche' => [
            'id' => 43114,
            'testnetId' => 43113,
            'axelarName' => 'Avalanche',
            'nativeToken' => 'AVAX',
            'coingeckoId' => 'avalanche-2',
            'decimals' => 18
        ],
        'moonbeam' => [
            'id' => 1284,
            'testnetId' => 1287,
            'axelarName' => 'Moonbeam',
            'nativeToken' => 'GLMR',
            'coingeckoId' => 'moonbeam',
            'decimals' => 18
        ],
        'arbitrum' => [
            'id' => 42161,
            'testnetId' => 421614,
            'axelarName' => 'arbitrum',
            'nativeToken' => 'ETH',
            'coingeckoId' => 'ethereum',
            'decimals' => 18
        ],
        'optimism' => [
            'id' => 10,
            'testnetId' => 11155420,
            'axelarName' => 'optimism',
            'nativeToken' => 'ETH',
            'coingeckoId' => 'ethereum',
            'decimals' => 18
        ],
        'base' => [
            'id' => 8453,
            'testnetId' => 84532,
            'axelarName' => 'base',
            'nativeToken' => 'ETH',
            'coingeckoId' => 'ethereum',
            'decimals' => 18
        ],
        'fantom' => [
            'id' => 250,
            'testnetId' => 4002,
            'axelarName' => 'Fantom',
            'nativeToken' => 'FTM',
            'coingeckoId' => 'fantom',
            'decimals' => 18
        ]
    ];
    
    // Token addresses by environment and chain
    private const TOKEN_ADDRESSES = [
        'mainnet' => [
            'USDC' => [
                'ethereum' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
                'polygon' => '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359',
                'avalanche' => '0xB97EF9Ef8734C71904D8002F8b6Bc66Dd9c48a6E',
                'bsc' => '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d',
                'moonbeam' => '0x931715FEE2d06333043d11F658C8CE934aC61D0c',
                'arbitrum' => '0xaf88d065e77c8cC2239327C5EDb3A432268e5831',
                'optimism' => '0x0b2C639c533813f4Aa9D7837CAf62653d097Ff85',
                'base' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
                'fantom' => '0x04068DA6C83AFCFA0e13ba15A6696662335D5B75'
            ],
            'USDT' => [
                'ethereum' => '0xdAC17F958D2ee523a2206206994597C13D831ec7',
                'polygon' => '0xc2132D05D31c914a87C6611C10748AEb04B58e8F',
                'avalanche' => '0x9702230A8Ea53601f5cD2dc00fDBc13d4dF4A8c7',
                'bsc' => '0x55d398326f99059fF775485246999027B3197955',
                'moonbeam' => '0xeFAeeE334F0Fd1712f9a8cc375f427D9Cdd40d73',
                'arbitrum' => '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9',
                'optimism' => '0x94b008aA00579c1307B0EF2c499aD98a8ce58e58',
                'fantom' => '0x049d68029688eAbF473097a2fC38ef61633A3C7A'
            ]
        ],
        'testnet' => [
            'aUSDC' => [
                'ethereum' => '0x254d06f33bDc5b8ee05b2ea472107E300226659A',
                'polygon' => '0x254d06f33bDc5b8ee05b2ea472107E300226659A',
                'avalanche' => '0x57F1c63497AEe0bE305B8852b354CEc793da43bB',
                'moonbeam' => '0xD1633F7Fb3d716643125d6415d4177bC36b7186b',
                'bsc' => '0x254d06f33bDc5b8ee05b2ea472107E300226659A'
            ]
        ]
    ];
    
    // ERC20 ABI (minimal for approve and transfer)
    private const ERC20_ABI = '[{"constant":false,"inputs":[{"name":"spender","type":"address"},{"name":"amount","type":"uint256"}],"name":"approve","outputs":[{"name":"","type":"bool"}],"type":"function"},{"constant":true,"inputs":[{"name":"owner","type":"address"}],"name":"balanceOf","outputs":[{"name":"","type":"uint256"}],"type":"function"},{"constant":false,"inputs":[{"name":"to","type":"address"},{"name":"amount","type":"uint256"}],"name":"transfer","outputs":[{"name":"","type":"bool"}],"type":"function"},{"constant":true,"inputs":[],"name":"decimals","outputs":[{"name":"","type":"uint8"}],"type":"function"},{"anonymous":false,"inputs":[{"indexed":true,"name":"from","type":"address"},{"indexed":true,"name":"to","type":"address"},{"name":"value","type":"uint256"}],"name":"Transfer","type":"event"}]';
    
    /**
     * Constructor
     * 
     * @param array $config Configuration with RPC URLs
     * @param string $environment 'mainnet' or 'testnet'
     * @param LoggerInterface|null $logger PSR-3 logger
     */
    public function __construct(array $config, string $environment = 'mainnet', ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->environment = $environment;
        $this->logger = $logger ?? new NullLogger();
        
        $this->httpClient = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'AxelarPHP-SDK/2.0'
            ]
        ]);
        
        $this->validateConfig();
        $this->logger->info('Axelar Bridge SDK initialized', [
            'environment' => $environment,
            'supported_chains' => array_keys(self::CHAINS)
        ]);
    }
    
    /**
     * Validate required configuration
     */
    private function validateConfig(): void
    {
        $required = ['ETHEREUM_RPC', 'BSC_RPC', 'POLYGON_RPC', 'MOONBEAM_RPC'];
        foreach ($required as $key) {
            if (empty($this->config[$key])) {
                throw new \InvalidArgumentException("Missing required config: {$key}");
            }
        }
    }
    
    /**
     * Calculate complete bridge fees with USD conversion
     * 
     * @param string $sourceChain Source chain (ethereum, bsc, polygon, etc)
     * @param float $amount Amount to bridge (in token units, e.g., 100 USDC)
     * @param string $token Token symbol (USDC or USDT)
     * @param string $destinationChain Destination chain (default: moonbeam)
     * @return array Complete fee breakdown
     */
    public function calculateBridgeFees(
        string $sourceChain,
        float $amount,
        string $token = 'USDC',
        string $destinationChain = 'moonbeam'
    ): array {
        $this->logger->info('Calculating bridge fees', [
            'sourceChain' => $sourceChain,
            'destinationChain' => $destinationChain,
            'amount' => $amount,
            'token' => $token
        ]);
        
        try {
            // 1. Get Axelar gas estimate
            $axelarGas = $this->estimateAxelarGas($sourceChain, $destinationChain, $amount, $token);
            
            // 2. Get current gas price on source chain
            $sourceGasPrice = $this->getGasPrice($sourceChain);
            
            // 3. Calculate transaction gas costs
            $approveGas = 50000; // Gas for ERC20 approve
            $bridgeGas = 150000; // Gas for gateway call
            $gasPaymentGas = 100000; // Gas for gas service payment
            
            $totalGasNeeded = $approveGas + $bridgeGas + $gasPaymentGas;
            $sourceChainGasCost = bcmul((string)$totalGasNeeded, $sourceGasPrice, 0);
            $sourceChainGasCostEther = bcdiv($sourceChainGasCost, '1e18', 18);
            
            // 4. Get native token prices in USD
            $sourceNativePrice = $this->getNativeTokenPrice($sourceChain);
            $sourceGasCostUSD = bcmul($sourceChainGasCostEther, (string)$sourceNativePrice, 2);
            
            // 5. Calculate Axelar relayer fee in USD
            $axelarGasWei = $axelarGas['gasFeeWei'];
            $axelarGasEther = bcdiv($axelarGasWei, '1e18', 18);
            $axelarGasUSD = bcmul($axelarGasEther, (string)$sourceNativePrice, 2);
            
            // 6. Calculate total fees
            $totalGasWei = bcadd($sourceChainGasCost, $axelarGasWei, 0);
            $totalGasEther = bcdiv($totalGasWei, '1e18', 18);
            $totalFeeUSD = bcadd($sourceGasCostUSD, $axelarGasUSD, 2);
            
            // 7. Calculate net amount received
            $netReceived = $amount; // No token deduction for GMP, only gas fees
            
            // 8. Get chain info for native token name
            $chainInfo = $this->getChainInfo($sourceChain);
            
            $result = [
                'sourceChain' => $sourceChain,
                'destinationChain' => $destinationChain,
                'amount' => $amount,
                'token' => $token,
                'fees' => [
                    'sourceChainGas' => [
                        'wei' => $sourceChainGasCost,
                        'ether' => $sourceChainGasCostEther,
                        'usd' => $sourceGasCostUSD,
                        'nativeToken' => $chainInfo['nativeToken'],
                        'breakdown' => [
                            'approveGas' => $approveGas,
                            'bridgeGas' => $bridgeGas,
                            'gasPaymentGas' => $gasPaymentGas
                        ]
                    ],
                    'axelarGas' => [
                        'wei' => $axelarGasWei,
                        'ether' => $axelarGasEther,
                        'usd' => $axelarGasUSD,
                        'nativeToken' => $chainInfo['nativeToken']
                    ],
                    'total' => [
                        'wei' => $totalGasWei,
                        'ether' => $totalGasEther,
                        'usd' => $totalFeeUSD,
                        'nativeToken' => $chainInfo['nativeToken']
                    ]
                ],
                'netReceived' => $netReceived,
                'nativeTokenPrice' => $sourceNativePrice,
                'estimatedTime' => '2-5 minutes',
                'gasPaymentRequired' => [
                    'amount' => $axelarGasWei,
                    'token' => $chainInfo['nativeToken'],
                    'amountFormatted' => $axelarGasEther . ' ' . $chainInfo['nativeToken']
                ]
            ];
            
            $this->logger->info('Bridge fees calculated', [
                'totalUSD' => $totalFeeUSD,
                'nativeToken' => $chainInfo['nativeToken']
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->logger->error('Fee calculation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new BridgeException('Failed to calculate bridge fees: ' . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Get gas fee information for user display
     * Shows which wallet needs native tokens for gas payment
     * 
     * @param string $sourceChain Source chain
     * @param float $amount Amount to bridge
     * @param string $token Token symbol
     * @return array Gas fee requirements
     */
    public function getGasRequirements(
        string $sourceChain,
        float $amount,
        string $token = 'USDC'
    ): array {
        $chainInfo = $this->getChainInfo($sourceChain);
        $fees = $this->calculateBridgeFees($sourceChain, $amount, $token);
        
        return [
            'chainName' => ucfirst($sourceChain),
            'nativeToken' => $chainInfo['nativeToken'],
            'requiredAmount' => $fees['fees']['total']['ether'],
            'requiredAmountWei' => $fees['fees']['total']['wei'],
            'usdValue' => $fees['fees']['total']['usd'],
            'message' => sprintf(
                'You need %s %s (%s USD) in your wallet on %s to pay for gas fees',
                $fees['fees']['total']['ether'],
                $chainInfo['nativeToken'],
                $fees['fees']['total']['usd'],
                ucfirst($sourceChain)
            ),
            'breakdown' => [
                'Transaction gas on source chain' => $fees['fees']['sourceChainGas']['usd'] . ' USD',
                'Axelar relayer fee (cross-chain)' => $fees['fees']['axelarGas']['usd'] . ' USD'
            ]
        ];
    }
    
    /**
     * Execute bridge transaction
     * 
     * @param string $sourceChain Source chain
     * @param string $destinationAddress Destination address on Moonbeam
     * @param float $amount Amount to bridge
     * @param string $token Token symbol
     * @param string $privateKey Private key for signing (must have native tokens for gas)
     * @return array Transaction details
     */
    public function executeBridge(
        string $sourceChain,
        string $destinationAddress,
        float $amount,
        string $token,
        string $privateKey
    ): array {
        $this->logger->info('Executing bridge transaction', [
            'sourceChain' => $sourceChain,
            'destinationAddress' => $destinationAddress,
            'amount' => $amount,
            'token' => $token
        ]);
        
        try {
            // 1. Validate inputs
            $this->validateBridgeParams($sourceChain, $destinationAddress, $amount, $token);
            
            // 2. Calculate fees
            $fees = $this->calculateBridgeFees($sourceChain, $amount, $token);
            
            // 3. Get addresses and setup
            $fromAddress = $this->getAddressFromPrivateKey($privateKey);
            $tokenAddress = $this->getTokenAddress($sourceChain, $token);
            $gatewayAddress = $this->getGatewayAddress();
            $gasServiceAddress = $this->getGasServiceAddress();
            
            // 4. Check balances
            $this->checkBalances($sourceChain, $fromAddress, $tokenAddress, $amount, $fees['fees']['total']['wei']);
            
            // 5. Execute transactions
            $web3 = $this->getWeb3($sourceChain);
            $chainId = $this->getChainId($sourceChain);
            
            // Step 1: Approve token spend
            $approveTx = $this->approveToken(
                $web3,
                $tokenAddress,
                $gatewayAddress,
                $amount,
                $token,
                $fromAddress,
                $privateKey,
                $chainId
            );
            
            $this->logger->info('Token approval submitted', ['txHash' => $approveTx]);
            
            // Wait for approval confirmation
            $this->waitForTransaction($web3, $approveTx, 30);
            
            // Step 2: Pay gas to Axelar Gas Service
            $payload = $this->encodePayload($destinationAddress);
            $gasPaymentTx = $this->payGasForContractCallWithToken(
                $web3,
                $gasServiceAddress,
                $gatewayAddress,
                'moonbeam',
                $destinationAddress,
                $payload,
                $token,
                $amount,
                $fees['fees']['axelarGas']['wei'],
                $fromAddress,
                $privateKey,
                $chainId
            );
            
            $this->logger->info('Gas payment submitted', ['txHash' => $gasPaymentTx]);
            
            // Wait for gas payment confirmation
            $this->waitForTransaction($web3, $gasPaymentTx, 30);
            
            // Step 3: Call gateway to bridge tokens
            $bridgeTx = $this->callContractWithToken(
                $web3,
                $gatewayAddress,
                'moonbeam',
                $destinationAddress,
                $payload,
                $token,
                $amount,
                $fromAddress,
                $privateKey,
                $chainId
            );
            
            $this->logger->info('Bridge transaction submitted', ['txHash' => $bridgeTx]);
            
            return [
                'success' => true,
                'transactions' => [
                    'approve' => $approveTx,
                    'gasPayment' => $gasPaymentTx,
                    'bridge' => $bridgeTx
                ],
                'mainTxHash' => $bridgeTx,
                'sourceChain' => $sourceChain,
                'destinationChain' => 'moonbeam',
                'destinationAddress' => $destinationAddress,
                'amount' => $amount,
                'token' => $token,
                'fees' => $fees,
                'estimatedArrival' => date('Y-m-d H:i:s', time() + 300), // ~5 minutes
                'trackingUrl' => "https://axelarscan.io/gmp/{$bridgeTx}"
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Bridge execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new BridgeException('Bridge execution failed: ' . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Monitor deposits to a specific address
     * 
     * @param string $chain Chain name
     * @param string $address Address to monitor
     * @param string $token Token symbol
     * @param int $fromBlock Starting block (0 = last 1000 blocks)
     * @return array List of deposits
     */
    public function monitorDeposits(
        string $chain,
        string $address,
        string $token = 'USDC',
        int $fromBlock = 0
    ): array {
        $this->logger->info('Monitoring deposits', [
            'chain' => $chain,
            'address' => $address,
            'token' => $token
        ]);
        
        try {
            $web3 = $this->getWeb3($chain);
            $tokenAddress = $this->getTokenAddress($chain, $token);
            
            // Get latest block
            $latestBlock = $this->getLatestBlockNumber($web3);
            $startBlock = $fromBlock > 0 ? $fromBlock : max(0, $latestBlock - 1000);
            
            // ERC20 Transfer event signature
            $transferTopic = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
            $addressTopic = '0x000000000000000000000000' . strtolower(substr($address, 2));
            
            // Get logs
            $logs = [];
            $web3->eth->getLogs([
                'fromBlock' => '0x' . dechex($startBlock),
                'toBlock' => 'latest',
                'address' => $tokenAddress,
                'topics' => [$transferTopic, null, $addressTopic]
            ], function($err, $result) use (&$logs) {
                if ($err !== null) {
                    throw new \RuntimeException('Failed to get logs: ' . $err->getMessage());
                }
                $logs = $result;
            });
            
            // Parse deposits
            $deposits = [];
            foreach ($logs as $log) {
                $amountHex = $log->data;
                $amount = gmp_strval(gmp_init($amountHex, 16));
                $decimals = $token === 'USDC' || $token === 'USDT' ? 6 : 18;
                $amountFormatted = bcdiv($amount, bcpow('10', (string)$decimals), $decimals);
                
                $deposits[] = [
                    'txHash' => $log->transactionHash,
                    'blockNumber' => hexdec($log->blockNumber),
                    'amount' => $amountFormatted,
                    'amountRaw' => $amount,
                    'token' => $token,
                    'from' => '0x' . substr($log->topics[1], 26),
                    'to' => $address,
                    'timestamp' => $this->getBlockTimestamp($web3, $log->blockNumber)
                ];
            }
            
            $this->logger->info('Deposits found', ['count' => count($deposits)]);
            
            return $deposits;
            
        } catch (\Exception $e) {
            $this->logger->error('Deposit monitoring failed', [
                'error' => $e->getMessage()
            ]);
            throw new BridgeException('Deposit monitoring failed: ' . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Get transaction status from Axelar network
     * 
     * @param string $txHash Transaction hash
     * @param string $sourceChain Source chain
     * @return array Status information
     */
    public function getTransactionStatus(string $txHash, string $sourceChain): array
    {
        try {
            $axelarChainName = $this->getAxelarChainName($sourceChain);
            $url = $this->environment === 'mainnet' 
                ? "https://api.axelarscan.io/api/v2/gmp/{$txHash}"
                : "https://testnet.api.axelarscan.io/api/v2/gmp/{$txHash}";
            
            $response = $this->httpClient->get($url, [
                'query' => ['sourceChain' => $axelarChainName]
            ]);
            
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('Failed to get transaction status');
            }
            
            $data = json_decode($response->getBody(), true);
            
            return [
                'txHash' => $txHash,
                'status' => $data['status'] ?? 'unknown',
                'sourceChain' => $sourceChain,
                'destinationChain' => $data['call']['returnValues']['destinationChain'] ?? 'moonbeam',
                'amount' => $data['call']['returnValues']['amount'] ?? '0',
                'token' => $data['call']['returnValues']['symbol'] ?? 'USDC',
                'approved' => $data['approved'] ?? false,
                'executed' => $data['executed'] ?? false,
                'error' => $data['error'] ?? null,
                'timeSpent' => $data['time_spent'] ?? null,
                'confirmations' => $data['confirmation'] ?? []
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Status check failed', [
                'txHash' => $txHash,
                'error' => $e->getMessage()
            ]);
            throw new BridgeException('Status check failed: ' . $e->getMessage(), 0, $e);
        }
    }
    
    // ==================== PRIVATE HELPER METHODS ====================
    
    /**
     * Estimate Axelar gas fees
     */
    private function estimateAxelarGas(string $sourceChain, string $destinationChain, float $amount, string $token): array
    {
        try {
            $endpoint = $this->environment === 'mainnet' 
                ? 'https://api.axelarscan.io'
                : 'https://testnet.api.axelarscan.io';
            
            $amountWei = bcmul((string)$amount, '1000000'); // Convert to 6 decimals
            
            $response = $this->httpClient->get("{$endpoint}/api/estimateGasFee", [
                'query' => [
                    'sourceChain' => $this->getAxelarChainName($sourceChain),
                    'destinationChain' => $this->getAxelarChainName($destinationChain),
                    'sourceTokenSymbol' => $token,
                    'amount' => $amountWei,
                    'gasLimit' => 400000,
                    'gasMultiplier' => 1.3
                ]
            ]);
            
            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody(), true);
                return [
                    'gasFeeWei' => $data['gasEstimate'] ?? $this->getFallbackGasEstimate($sourceChain),
                    'executionFee' => $data['executionFee'] ?? '0',
                    'baseFee' => $data['baseFee'] ?? '0'
                ];
            }
            
        } catch (\Exception $e) {
            $this->logger->warning('Axelar API failed, using fallback estimate', [
                'error' => $e->getMessage()
            ]);
        }
        
        return ['gasFeeWei' => $this->getFallbackGasEstimate($sourceChain)];
    }
    
    /**
     * Fallback gas estimates (conservative)
     */
    private function getFallbackGasEstimate(string $chain): string
    {
        $estimates = [
            'ethereum' => bcmul('0.003', '1e18'),
            'polygon' => bcmul('0.5', '1e18'),
            'bsc' => bcmul('0.002', '1e18'),
            'avalanche' => bcmul('0.05', '1e18'),
            'moonbeam' => bcmul('0.1', '1e18'),
            'arbitrum' => bcmul('0.0005', '1e18'),
            'optimism' => bcmul('0.0005', '1e18'),
            'base' => bcmul('0.0005', '1e18'),
            'fantom' => bcmul('0.05', '1e18')
        ];
        
        return $estimates[$chain] ?? bcmul('0.05', '1e18');
    }
    
    /**
     * Get current gas price on chain
     */
    private function getGasPrice(string $chain): string
    {
        $web3 = $this->getWeb3($chain);
        $gasPrice = '0';
        
        $web3->eth->gasPrice(function($err, $price) use (&$gasPrice) {
            if ($err === null) {
                $gasPrice = $price->toString();
            }
        });
        
        if ($gasPrice === '0') {
            // Fallback gas prices
            $fallbackPrices = [
                'ethereum' => bcmul('30', '1e9'), // 30 gwei
                'polygon' => bcmul('100', '1e9'), // 100 gwei
                'bsc' => bcmul('5', '1e9'), // 5 gwei
                'avalanche' => bcmul('25', '1e9'), // 25 gwei
                'moonbeam' => bcmul('100', '1e9'), // 100 gwei
                'arbitrum' => bcmul('0.1', '1e9'), // 0.1 gwei
                'optimism' => bcmul('0.001', '1e9'), // 0.001 gwei
                'base' => bcmul('0.1', '1e9'),
                'fantom' => bcmul('50', '1e9')
            ];
            $gasPrice = $fallbackPrices[$chain] ?? bcmul('50', '1e9');
        }
        
        return $gasPrice;
    }
    
    /**
     * Get native token price in USD from CoinGecko
     */
    private function getNativeTokenPrice(string $chain): float
    {
        $chainInfo = $this->getChainInfo($chain);
        $coingeckoId = $chainInfo['coingeckoId'];
        
        try {
            $response = $this->httpClient->get('https://api.coingecko.com/api/v3/simple/price', [
                'query' => [
                    'ids' => $coingeckoId,
                    'vs_currencies' => 'usd'
                ]
            ]);
            
            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody(), true);
                $price = $data[$coingeckoId]['usd'] ?? null;
                
                if ($price) {
                    $this->logger->debug('Got price from CoinGecko', [
                        'token' => $chainInfo['nativeToken'],
                        'price' => $price
                    ]);
                    return (float)$price;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('CoinGecko API failed, using fallback price', [
                'error' => $e->getMessage()
            ]);
        }
        
        // Fallback prices (update these periodically)
        $fallbackPrices = [
            'ethereum' => 2500.0,
            'binancecoin' => 300.0,
            'matic-network' => 0.8,
            'avalanche-2' => 30.0,
            'moonbeam' => 0.3,
            'fantom' => 0.5
        ];
        
        return $fallbackPrices[$coingeckoId] ?? 1.0;
    }
    
    /**
     * Approve token spending
     */
    private function approveToken(
        Web3 $web3,
        string $tokenAddress,
        string $spender,
        float $amount,
        string $tokenSymbol,
        string $fromAddress,
        string $privateKey,
        int $chainId
    ): string {
        $this->logger->info('Approving token spend', [
            'token' => $tokenSymbol,
            'spender' => $spender,
            'amount' => $amount
        ]);
        
        $contract = new Contract($web3->provider, self::ERC20_ABI);
        $contract->at($tokenAddress);
        
        // Convert amount to smallest unit (6 decimals for USDC/USDT)
        $decimals = ($tokenSymbol === 'USDC' || $tokenSymbol === 'USDT') ? 6 : 18;
        $amountWei = bcmul((string)$amount, bcpow('10', (string)$decimals));
        
        // Encode approve function call
        $data = $contract->getData('approve', $spender, $amountWei);
        
        // Build and sign transaction
        $txParams = [
            'from' => $fromAddress,
            'to' => $tokenAddress,
            'data' => $data,
            'chainId' => $chainId
        ];
        
        return $this->signAndSendTransaction($web3, $txParams, $privateKey);
    }
    
    /**
     * Pay gas to Axelar Gas Service
     */
    private function payGasForContractCallWithToken(
        Web3 $web3,
        string $gasService,
        string $gateway,
        string $destinationChain,
        string $destinationAddress,
        string $payload,
        string $symbol,
        float $amount,
        string $gasPaymentWei,
        string $fromAddress,
        string $privateKey,
        int $chainId
    ): string {
        $this->logger->info('Paying gas for cross-chain call');
        
        // payNativeGasForContractCallWithToken(
        //     address sender,
        //     string destinationChain,
        //     string destinationAddress,
        //     bytes payload,
        //     string symbol,
        //     uint256 amount,
        //     address refundAddress
        // )
        
        $functionSignature = 'payNativeGasForContractCallWithToken(address,string,string,bytes,string,uint256,address)';
        $selector = '0x' . substr(hash('sha3-256', $functionSignature), 0, 8);
        
        $axelarDestChain = $this->getAxelarChainName($destinationChain);
        $decimals = ($symbol === 'USDC' || $symbol === 'USDT') ? 6 : 18;
        $amountWei = bcmul((string)$amount, bcpow('10', (string)$decimals));
        
        // Encode parameters
        $params = $this->encodeABI([
            ['address', $gateway],
            ['string', $axelarDestChain],
            ['string', $destinationAddress],
            ['bytes', $payload],
            ['string', $symbol],
            ['uint256', $amountWei],
            ['address', $fromAddress]
        ]);
        
        $data = $selector . $params;
        
        $txParams = [
            'from' => $fromAddress,
            'to' => $gasService,
            'value' => '0x' . dechex($gasPaymentWei),
            'data' => $data,
            'chainId' => $chainId
        ];
        
        return $this->signAndSendTransaction($web3, $txParams, $privateKey);
    }
    
    /**
     * Call Axelar Gateway to bridge tokens
     */
    private function callContractWithToken(
        Web3 $web3,
        string $gateway,
        string $destinationChain,
        string $destinationAddress,
        string $payload,
        string $symbol,
        float $amount,
        string $fromAddress,
        string $privateKey,
        int $chainId
    ): string {
        $this->logger->info('Calling gateway contract');
        
        // callContractWithToken(
        //     string destinationChain,
        //     string destinationContractAddress,
        //     bytes payload,
        //     string symbol,
        //     uint256 amount
        // )
        
        $functionSignature = 'callContractWithToken(string,string,bytes,string,uint256)';
        $selector = '0x' . substr(hash('sha3-256', $functionSignature), 0, 8);
        
        $axelarDestChain = $this->getAxelarChainName($destinationChain);
        $decimals = ($symbol === 'USDC' || $symbol === 'USDT') ? 6 : 18;
        $amountWei = bcmul((string)$amount, bcpow('10', (string)$decimals));
        
        // Encode parameters
        $params = $this->encodeABI([
            ['string', $axelarDestChain],
            ['string', $destinationAddress],
            ['bytes', $payload],
            ['string', $symbol],
            ['uint256', $amountWei]
        ]);
        
        $data = $selector . $params;
        
        $txParams = [
            'from' => $fromAddress,
            'to' => $gateway,
            'data' => $data,
            'chainId' => $chainId
        ];
        
        return $this->signAndSendTransaction($web3, $txParams, $privateKey);
    }
    
    /**
     * Sign and send transaction using Web3.php
     */
    private function signAndSendTransaction(Web3 $web3, array $txParams, string $privateKey): string
    {
        // Ensure private key format
        $privateKey = str_replace('0x', '', $privateKey);
        
        // Get nonce
        $nonce = $this->getNonce($web3, $txParams['from']);
        $txParams['nonce'] = '0x' . dechex($nonce);
        
        // Estimate gas if not provided
        if (!isset($txParams['gas'])) {
            $gasEstimate = $this->estimateGas($web3, $txParams);
            $txParams['gas'] = '0x' . dechex((int)($gasEstimate * 1.2)); // Add 20% buffer
        }
        
        // Get gas price if not provided
        if (!isset($txParams['gasPrice'])) {
            $gasPrice = '0';
            $web3->eth->gasPrice(function($err, $price) use (&$gasPrice) {
                if ($err === null) {
                    $gasPrice = $price->toString();
                }
            });
            $txParams['gasPrice'] = '0x' . dechex($gasPrice);
        }
        
        // Ensure value is set
        if (!isset($txParams['value'])) {
            $txParams['value'] = '0x0';
        }
        
        // Sign transaction
        $transaction = new \Web3p\EthereumTx\Transaction($txParams);
        $signedTx = '0x' . $transaction->sign($privateKey);
        
        // Send transaction
        $txHash = '';
        $web3->eth->sendRawTransaction($signedTx, function($err, $hash) use (&$txHash) {
            if ($err !== null) {
                throw new \RuntimeException('Transaction failed: ' . $err->getMessage());
            }
            $txHash = $hash;
        });
        
        if (empty($txHash)) {
            throw new \RuntimeException('Failed to send transaction');
        }
        
        $this->logger->info('Transaction sent', ['txHash' => $txHash]);
        
        return $txHash;
    }
    
    /**
     * Wait for transaction confirmation
     */
    private function waitForTransaction(Web3 $web3, string $txHash, int $timeoutSeconds = 60): void
    {
        $this->logger->info('Waiting for transaction confirmation', ['txHash' => $txHash]);
        
        $startTime = time();
        while (time() - $startTime < $timeoutSeconds) {
            $receipt = null;
            $web3->eth->getTransactionReceipt($txHash, function($err, $result) use (&$receipt) {
                if ($err === null && $result !== null) {
                    $receipt = $result;
                }
            });
            
            if ($receipt !== null) {
                $status = property_exists($receipt, 'status') ? hexdec($receipt->status) : 1;
                
                if ($status === 1) {
                    $this->logger->info('Transaction confirmed', [
                        'txHash' => $txHash,
                        'blockNumber' => hexdec($receipt->blockNumber)
                    ]);
                    return;
                } else {
                    throw new \RuntimeException('Transaction failed: ' . $txHash);
                }
            }
            
            sleep(2);
        }
        
        throw new \RuntimeException('Transaction confirmation timeout: ' . $txHash);
    }
    
    /**
     * Get nonce for address
     */
    private function getNonce(Web3 $web3, string $address): int
    {
        $nonce = 0;
        $web3->eth->getTransactionCount($address, 'pending', function($err, $count) use (&$nonce) {
            if ($err === null) {
                $nonce = (int)$count->toString();
            }
        });
        return $nonce;
    }
    
    /**
     * Estimate gas for transaction
     */
    private function estimateGas(Web3 $web3, array $txParams): int
    {
        $gas = 100000; // Default fallback
        
        $estimateParams = [
            'from' => $txParams['from'],
            'to' => $txParams['to'],
            'data' => $txParams['data'] ?? '0x'
        ];
        
        if (isset($txParams['value'])) {
            $estimateParams['value'] = $txParams['value'];
        }
        
        $web3->eth->estimateGas($estimateParams, function($err, $estimate) use (&$gas) {
            if ($err === null) {
                $gas = (int)$estimate->toString();
            }
        });
        
        return $gas;
    }
    
    /**
     * Get latest block number
     */
    private function getLatestBlockNumber(Web3 $web3): int
    {
        $blockNumber = 0;
        $web3->eth->blockNumber(function($err, $number) use (&$blockNumber) {
            if ($err === null) {
                $blockNumber = (int)$number->toString();
            }
        });
        return $blockNumber;
    }
    
    /**
     * Get block timestamp
     */
    private function getBlockTimestamp(Web3 $web3, string $blockNumber): int
    {
        $timestamp = time();
        $web3->eth->getBlockByNumber($blockNumber, false, function($err, $block) use (&$timestamp) {
            if ($err === null && $block !== null) {
                $timestamp = hexdec($block->timestamp);
            }
        });
        return $timestamp;
    }
    
    /**
     * Check if user has sufficient balances
     */
    private function checkBalances(
        string $chain,
        string $address,
        string $tokenAddress,
        float $amount,
        string $gasRequiredWei
    ): void {
        $web3 = $this->getWeb3($chain);
        
        // Check native token balance for gas
        $nativeBalance = '0';
        $web3->eth->getBalance($address, function($err, $balance) use (&$nativeBalance) {
            if ($err === null) {
                $nativeBalance = $balance->toString();
            }
        });
        
        if (bccomp($nativeBalance, $gasRequiredWei) < 0) {
            $chainInfo = $this->getChainInfo($chain);
            $required = bcdiv($gasRequiredWei, '1e18', 6);
            $has = bcdiv($nativeBalance, '1e18', 6);
            
            throw new BridgeException(
                "Insufficient {$chainInfo['nativeToken']} for gas. Required: {$required}, Have: {$has}"
            );
        }
        
        // Check token balance
        $contract = new Contract($web3->provider, self::ERC20_ABI);
        $contract->at($tokenAddress);
        
        $tokenBalance = '0';
        $contract->call('balanceOf', $address, function($err, $result) use (&$tokenBalance) {
            if ($err === null && isset($result[0])) {
                $tokenBalance = $result[0]->toString();
            }
        });
        
        $decimals = 6; // USDC/USDT
        $requiredTokenWei = bcmul((string)$amount, bcpow('10', (string)$decimals));
        
        if (bccomp($tokenBalance, $requiredTokenWei) < 0) {
            $has = bcdiv($tokenBalance, bcpow('10', (string)$decimals), $decimals);
            throw new BridgeException(
                "Insufficient token balance. Required: {$amount}, Have: {$has}"
            );
        }
        
        $this->logger->info('Balance check passed', [
            'address' => $address,
            'nativeBalance' => bcdiv($nativeBalance, '1e18', 6),
            'tokenBalance' => bcdiv($tokenBalance, bcpow('10', (string)$decimals), $decimals)
        ]);
    }
    
    /**
     * Encode payload for destination contract
     */
    private function encodePayload(string $destinationAddress): string
    {
        // Simple payload: just the destination address
        // You can extend this for custom contract calls
        return '0x' . str_pad(substr($destinationAddress, 2), 64, '0', STR_PAD_LEFT);
    }
    
    /**
     * Encode ABI parameters
     */
    private function encodeABI(array $params): string
    {
        $encoded = '';
        $dynamicData = '';
        $dynamicOffset = count($params) * 32;
        
        foreach ($params as $param) {
            list($type, $value) = $param;
            
            if ($type === 'address') {
                $encoded .= str_pad(substr($value, 2), 64, '0', STR_PAD_LEFT);
            } elseif ($type === 'uint256') {
                $encoded .= str_pad(dechex($value), 64, '0', STR_PAD_LEFT);
            } elseif ($type === 'string' || $type === 'bytes') {
                // Dynamic type - add offset
                $encoded .= str_pad(dechex($dynamicOffset), 64, '0', STR_PAD_LEFT);
                
                // Add actual data to dynamic section
                if ($type === 'string') {
                    $hexValue = bin2hex($value);
                    $length = strlen($value);
                } else {
                    $hexValue = str_replace('0x', '', $value);
                    $length = strlen($hexValue) / 2;
                }
                
                $dynamicData .= str_pad(dechex($length), 64, '0', STR_PAD_LEFT);
                $dynamicData .= str_pad($hexValue, ceil(strlen($hexValue) / 64) * 64, '0', STR_PAD_RIGHT);
                
                $dynamicOffset += 32 + (int)(ceil(strlen($hexValue) / 64) * 32);
            }
        }
        
        return $encoded . $dynamicData;
    }
    
    /**
     * Validate bridge parameters
     */
    private function validateBridgeParams(
        string $sourceChain,
        string $destinationAddress,
        float $amount,
        string $token
    ): void {
        if (!isset(self::CHAINS[$sourceChain])) {
            throw new \InvalidArgumentException("Unsupported source chain: {$sourceChain}");
        }
        
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $destinationAddress)) {
            throw new \InvalidArgumentException("Invalid destination address format");
        }
        
        if ($amount <= 0) {
            throw new \InvalidArgumentException("Amount must be greater than 0");
        }
        
        if (!in_array($token, ['USDC', 'USDT'])) {
            throw new \InvalidArgumentException("Unsupported token: {$token}");
        }
        
        $tokenAddress = $this->getTokenAddress($sourceChain, $token);
        if (!$tokenAddress) {
            throw new \InvalidArgumentException("Token {$token} not available on {$sourceChain}");
        }
    }
    
    /**
     * Get address from private key
     */
    private function getAddressFromPrivateKey(string $privateKey): string
    {
        $privateKey = str_replace('0x', '', $privateKey);
        
        $secp256k1 = new \Elliptic\EC('secp256k1');
        $keyPair = $secp256k1->keyFromPrivate($privateKey, 'hex');
        $publicKey = $keyPair->getPublic(false, 'hex');
        
        // Remove '04' prefix and hash
        $publicKey = substr($publicKey, 2);
        $hash = hash('sha3-256', hex2bin($publicKey));
        
        return '0x' . substr($hash, -40);
    }
    
    /**
     * Get Web3 instance for chain
     */
    private function getWeb3(string $chain): Web3
    {
        $rpcUrl = $this->getRPCUrl($chain);
        return new Web3(new HttpProvider(new HttpRequestManager($rpcUrl)));
    }
    
    /**
     * Get RPC URL for chain
     */
    private function getRPCUrl(string $chain): string
    {
        $key = strtoupper($chain) . '_RPC';
        $rpcUrl = $this->config[$key] ?? null;
        
        if (!$rpcUrl) {
            throw new \InvalidArgumentException("No RPC URL configured for {$chain}");
        }
        
        return $rpcUrl;
    }
    
    /**
     * Get chain ID
     */
    private function getChainId(string $chain): int
    {
        $chainInfo = self::CHAINS[$chain] ?? null;
        if (!$chainInfo) {
            throw new \InvalidArgumentException("Unknown chain: {$chain}");
        }
        
        return $this->environment === 'mainnet' 
            ? $chainInfo['id'] 
            : $chainInfo['testnetId'];
    }
    
    /**
     * Get chain information
     */
    private function getChainInfo(string $chain): array
    {
        $info = self::CHAINS[$chain] ?? null;
        if (!$info) {
            throw new \InvalidArgumentException("Unknown chain: {$chain}");
        }
        return $info;
    }
    
    /**
     * Get Axelar chain name
     */
    private function getAxelarChainName(string $chain): string
    {
        return self::CHAINS[$chain]['axelarName'] ?? ucfirst($chain);
    }
    
    /**
     * Get token contract address
     */
    private function getTokenAddress(string $chain, string $token): ?string
    {
        return self::TOKEN_ADDRESSES[$this->environment][$token][$chain] ?? null;
    }
    
    /**
     * Get Axelar Gateway address
     */
    private function getGatewayAddress(): string
    {
        return $this->environment === 'mainnet' 
            ? self::MAINNET_GATEWAY 
            : self::TESTNET_GATEWAY;
    }
    
    /**
     * Get Axelar Gas Service address
     */
    private function getGasServiceAddress(): string
    {
        return $this->environment === 'mainnet' 
            ? self::MAINNET_GAS_SERVICE 
            : self::TESTNET_GAS_SERVICE;
    }
}

/**
 * Bridge-specific exception class
 */
class BridgeException extends \Exception
{
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}