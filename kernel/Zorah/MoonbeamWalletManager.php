<?php

/**
 * Production-Ready HD Wallet Manager for Multi-Chain Support
 * 
 * Features:
 * - HD wallet with BIP39 mnemonic (12/24 words)
 * - BIP32/BIP44 hierarchical deterministic key derivation
 * - EVM support only: Ethereum, Moonbeam and other EVM chains
 * - Secure encrypted storage with Halite/libsodium
 * - Transaction signing and sending
 * - Gas estimation and management
 * - Comprehensive error handling
 * 
 */

namespace Manomite\Zorah;

use Web3\Web3;
use Web3\Contract;
use Web3\Utils;
use Web3\Providers\HttpProvider;
use Web3p\EthereumTx\Transaction;
use kornrunner\Keccak;
use Sop\CryptoTypes\Asymmetric\EC\ECPublicKey;
use Sop\CryptoTypes\Asymmetric\EC\ECPrivateKey;
use Sop\CryptoEncoding\PEM;
use \Exception;

class MoonbeamWalletManager
{
    private Web3 $web3;
    private string $network; // 'testnet' | 'mainnet'
    private string $chain;   // 'moonbeam' | 'ethereum' | 'bsc'
    private int $chainId;
    private string $rpcUrl;
    private ?string $gasWalletPrivateKey = null;
    private ?string $gasWalletAddress = null;
    private ?string $masterSeed = null;
    private ?string $currentMnemonic = null;

    // BIP44 coin types (EVM only)
    private const COIN_TYPES = [
        'evm' => 60 // Ethereum and EVM chains
    ];

    private const NETWORKS = [
        'testnet' => [
            'moonbeam' => [
                'name' => 'Moonbase Alpha',
                'rpc' => 'https://moonbase-alpha.drpc.org',
                'chainId' => 1287,
                'symbol' => 'DEV',
                'explorer' => 'https://moonbase.moonscan.io',
                'decimals' => 18
            ],
            'ethereum' => [
                'name' => 'Ethereum Sepolia',
                'rpc' => 'https://rpc.sepolia.org',
                'chainId' => 11155111,
                'symbol' => 'ETH',
                'explorer' => 'https://sepolia.etherscan.io',
                'decimals' => 18
            ],
            'bsc' => [
                'name' => 'BNB Smart Chain Testnet',
                'rpc' => 'https://bsc-testnet.publicnode.com',
                'chainId' => 97,
                'symbol' => 'tBNB',
                'explorer' => 'https://testnet.bscscan.com',
                'decimals' => 18
            ]
        ],
        'mainnet' => [
            'moonbeam' => [
                'name' => 'Moonbeam',
                'rpc' => 'https://rpc.api.moonbeam.network',
                'chainId' => 1284,
                'symbol' => 'GLMR',
                'explorer' => 'https://moonscan.io',
                'decimals' => 18
            ],
            'ethereum' => [
                'name' => 'Ethereum',
                'rpc' => 'https://cloudflare-eth.com',
                'chainId' => 1,
                'symbol' => 'ETH',
                'explorer' => 'https://etherscan.io',
                'decimals' => 18
            ],
            'bsc' => [
                'name' => 'BNB Smart Chain',
                'rpc' => 'https://bsc-dataseed.binance.org',
                'chainId' => 56,
                'symbol' => 'BNB',
                'explorer' => 'https://bscscan.com',
                'decimals' => 18
            ]
        ]
    ];

    /**
     * Initialize wallet manager
     *
     * @param string $network 'testnet' or 'mainnet'
     * @param string|null $chain Chain within network: 'moonbeam' | 'ethereum' | 'bsc' (default 'moonbeam')
     * @param string|null $gasPrivateKey Private key for gas wallet
     * @param float $timeout Request timeout in seconds
     * @throws Exception
     */
    public function __construct(
        string $network = 'testnet',
        ?string $chain = null,
        ?string $gasPrivateKey = null,
        float $timeout = 30.0
    ) {
        if (!isset(self::NETWORKS[$network])) {
            throw new Exception("Invalid network: $network. Use 'testnet' or 'mainnet'");
        }

        $selectedChain = $chain ?? 'moonbeam';
        if (!isset(self::NETWORKS[$network][$selectedChain])) {
            throw new Exception("Invalid chain '$selectedChain' for network '$network'");
        }

        $config = self::NETWORKS[$network][$selectedChain];
        $this->network = $network;
        $this->chain = $selectedChain;
        $this->chainId = $config['chainId'];
        $this->rpcUrl = $config['rpc'];

        // Initialize Web3 with HttpProvider directly (web3p/web3.php doesn't use HttpRequestManager)
        $this->web3 = new Web3(new HttpProvider($this->rpcUrl, $timeout));

        if ($gasPrivateKey) {
            $this->setGasWallet($gasPrivateKey);
        }
    }

    /**
     * Set gas wallet for paying transaction fees
     *
     * @param string $privateKey Private key (with or without 0x prefix)
     * @return array ['address' => string, 'balance' => string]
     * @throws Exception
     */
    public function setGasWallet(string $privateKey): array
    {
        $normalized = $this->normalizePrivateKey($privateKey);

        if (strlen($normalized) !== 64) {
            throw new Exception('Invalid private key length');
        }

        // Store raw private key for gas wallet (caller acknowledged they manage this)
        $this->gasWalletPrivateKey = $normalized;
        $this->gasWalletAddress = $this->privateKeyToAddress($normalized);

        $balance = $this->getBalance($this->gasWalletAddress);

        return [
            'address' => $this->gasWalletAddress,
            'balance' => $balance
        ];
    }

    /**
     * Create HD wallet with password encryption (generates mnemonic automatically)
     * This is the recommended method for new wallet creation with password protection.
     *
     * @param string $password Password to encrypt the mnemonic
     * @param int $wordCount 12 or 24 words (default: 12)
     * @return array HD wallet with encrypted mnemonic and all chain addresses
     */
    public function createWalletWithPassword(string $password, int $wordCount = 12): array
    {
        if (empty($password)) {
            throw new Exception('Password is required to create an encrypted wallet');
        }

        // Generate new HD wallet with encrypted mnemonic
        $hdWallet = $this->createHDWallet(null, $password, $wordCount);

        // Return format compatible with old method but with HD wallet benefits
        return [
            'address' => $hdWallet['evm']['address'],
            'publicKey' => $hdWallet['evm']['publicKey'],
            'encryptedPrivateKey' => $hdWallet['encrypted'], // Actually encrypted mnemonic
            'mnemonic' => $hdWallet['mnemonic'],
            'network' => $this->network,
            'chainId' => $this->chainId,
            // EVM only
            'allChains' => [
                'evm' => $hdWallet['evm']
            ]
        ];
    }

    /**
     * Decrypt an encrypted blob and return the default EVM private key (index 0).
     * Note: The encrypted blob stores the mnemonic. We decrypt it, derive m/44'/60'/0'/0/0,
     * and return the corresponding private key as a 0x-prefixed hex string.
     *
     * Compatibility: ContractManager::createWalletAndApprove expects this to return
     * a usable EVM private key for signing permits.
     */
    public function decryptPrivateKey(string $blob, string $password): string
    {
        $mnemonic = $this->decryptMnemonic($blob, $password);
        // Initialize HD wallet context with the decrypted mnemonic
        $this->createHDWallet($mnemonic);
        $evm = $this->deriveAddress('evm', 0);
        return $evm['privateKey'];
    }

    /**
     * Restore full HD wallet from encrypted blob and password
     *
     * @param string $encryptedBlob Encrypted mnemonic blob
     * @param string $password Password used to encrypt
     * @return array Full HD wallet with all chain addresses
     */
    public function restoreWalletWithPassword(string $encryptedBlob, string $password): array
    {
        $mnemonic = $this->decryptMnemonic($encryptedBlob, $password);
        $hdWallet = $this->createHDWallet($mnemonic);

        return [
            'address' => $hdWallet['evm']['address'],
            'publicKey' => $hdWallet['evm']['publicKey'],
            'mnemonic' => $mnemonic,
            'network' => $this->network,
            'chainId' => $this->chainId,
            'allChains' => [
                'evm' => $hdWallet['evm']
            ]
        ];
    }

    /**
     * Create HD wallet with mnemonic (supports multi-chain)
     *
     * @param string|null $mnemonic Optional existing mnemonic (12 or 24 words)
     * @param string|null $password Optional password for mnemonic encryption
     * @param int $wordCount 12 or 24 words (default: 12)
     * @return array Wallet details with mnemonic and derived addresses
     */
    public function createHDWallet(?string $mnemonic = null, ?string $password = null, int $wordCount = 12): array
    {
        if ($mnemonic === null) {
            $strength = $wordCount === 24 ? 256 : 128;
            $mnemonic = $this->generateMnemonic($strength);
        }

        if (!$this->validateMnemonic($mnemonic)) {
            throw new Exception('Invalid mnemonic phrase');
        }

        $this->currentMnemonic = $mnemonic;
        $this->masterSeed = $this->mnemonicToSeed($mnemonic);

        // Derive default addresses (EVM only)
        $evmWallet = $this->deriveAddress('evm', 0);
        $result = [
            'mnemonic' => $mnemonic,
            'evm' => [
                'address' => $evmWallet['address'],
                'publicKey' => $evmWallet['publicKey'],
                'path' => "m/44'/60'/0'/0/0",
                'networks' => ['ethereum', 'moonbeam', 'polygon', 'bsc', 'avalanche']
            ],
            'network' => $this->network,
            'chainId' => $this->chainId
        ];

        // Encrypt mnemonic if password provided
        if ($password) {
            $result['encrypted'] = $this->encryptMnemonic($mnemonic, $password);
        }

        return $result;
    }

    /**
     * Derive address for specific chain and account index
     *
    * @param string $chain 'evm'
     * @param int $accountIndex Account index (default: 0)
     * @return array Address, public key, and private key
     */
    public function deriveAddress(string $chain, int $accountIndex = 0): array
    {
        if (!isset(self::COIN_TYPES[$chain])) {
            throw new Exception("Unsupported chain: $chain");
        }

        if ($this->masterSeed === null) {
            throw new Exception('HD wallet not initialized. Call createHDWallet() first.');
        }

        $coinType = self::COIN_TYPES[$chain];
        $path = "m/44'/{$coinType}'/0'/0/{$accountIndex}";

        $privateKey = $this->derivePath($path);

        // Only EVM supported
        return $this->deriveEVMAddress($privateKey, $path);
    }

    /**
     * Derive EVM address from private key
     */
    private function deriveEVMAddress(string $privateKey, string $path): array
    {
        $publicKey = $this->getPublicKey($privateKey);
        $address = $this->publicKeyToAddress($publicKey);

        return [
            'address' => $address,
            'publicKey' => '0x' . $publicKey,
            'privateKey' => '0x' . $privateKey,
            'path' => $path,
            'chain' => 'evm'
        ];
    }

    // Solana and Cosmos derivation removed; EVM only

    /**
     * Compress secp256k1 public key (for Cosmos/Bitcoin compatibility)
     */
    private function compressPublicKey(string $publicKeyHex): string
    {
        $pubKey = hex2bin($publicKeyHex);
        $x = substr($pubKey, 0, 32);
        $y = substr($pubKey, 32, 32);

        // Check if y is even or odd
        $yInt = gmp_init('0x' . bin2hex($y));
        $prefix = gmp_testbit($yInt, 0) ? '03' : '02';

        return $prefix . bin2hex($x);
    }

    /**
     * Derive private key from HD path (BIP32/BIP44)
     */
    private function derivePath(string $path): string
    {
        $segments = explode('/', trim($path, 'm/'));

        // Start with master key
        $I = hash_hmac('sha512', $this->masterSeed, 'Bitcoin seed', true);
        $key = substr($I, 0, 32);
        $chain = substr($I, 32, 32);

        $n = gmp_init('0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141', 16);

        foreach ($segments as $segment) {
            $hardened = str_ends_with($segment, "'");
            $index = (int)str_replace("'", '', $segment);

            if ($hardened) {
                $index |= 0x80000000;
            }

            // CKD (Child Key Derivation)
            $data = $hardened ? "\x00" . $key . pack('N', $index) : $this->getPublicKeyFromPrivate($key) . pack('N', $index);
            $I = hash_hmac('sha512', $data, $chain, true);
            $IL = substr($I, 0, 32);
            $IR = substr($I, 32, 32);

            $ILgmp = gmp_init('0x' . bin2hex($IL));
            $keyGmp = gmp_init('0x' . bin2hex($key));
            $childKeyGmp = gmp_mod(gmp_add($ILgmp, $keyGmp), $n);

            if (gmp_cmp($childKeyGmp, 0) === 0) {
                throw new Exception('Invalid child key derived');
            }

            $childKeyHex = str_pad(gmp_strval($childKeyGmp, 16), 64, '0', STR_PAD_LEFT);
            $key = hex2bin($childKeyHex);
            $chain = $IR;
        }

        return bin2hex($key);
    }

    /**
     * Get public key bytes from private key for non-hardened derivation
     */
    private function getPublicKeyFromPrivate(string $privateKeyBin): string
    {
        $privateKeyHex = bin2hex($privateKeyBin);
        $publicKeyHex = $this->getPublicKey($privateKeyHex);

        // Return compressed public key
        $compressedHex = $this->compressPublicKey($publicKeyHex);
        return hex2bin($compressedHex);
    }

    /**
     * Encrypt mnemonic with password using libsodium
     */
    public function encryptMnemonic(string $mnemonic, string $password): string
    {
        if (!function_exists('sodium_crypto_pwhash')) {
            throw new Exception('libsodium is required for encryption');
        }

        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $password,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
        );

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($mnemonic, '', $nonce, $key);

        sodium_memzero($key);

        return base64_encode(json_encode([
            'ct' => base64_encode($cipher),
            'salt' => base64_encode($salt),
            'nonce' => base64_encode($nonce),
            'kdf' => 'argon2id'
        ]));
    }

    /**
     * Decrypt encrypted mnemonic
     */
    public function decryptMnemonic(string $encrypted, string $password): string
    {
        if (!function_exists('sodium_crypto_pwhash')) {
            throw new Exception('libsodium is required for decryption');
        }

        $data = json_decode(base64_decode($encrypted), true);
        if (!$data || !isset($data['ct'], $data['salt'], $data['nonce'])) {
            throw new Exception('Invalid encrypted data');
        }

        $salt = base64_decode($data['salt']);
        $nonce = base64_decode($data['nonce']);
        $cipher = base64_decode($data['ct']);

        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $password,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
        );

        $mnemonic = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, '', $nonce, $key);

        sodium_memzero($key);

        if ($mnemonic === false) {
            throw new Exception('Failed to decrypt (incorrect password?)');
        }

        return $mnemonic;
    }

    /**
     * Restore HD wallet from encrypted mnemonic
     */
    public function restoreFromEncrypted(string $encrypted, string $password): array
    {
        $mnemonic = $this->decryptMnemonic($encrypted, $password);
        return $this->createHDWallet($mnemonic);
    }

    // Removed base58/bech32 utilities; EVM-only

    /**
     * Create wallet with mnemonic phrase (BIP39) - DEPRECATED, use createHDWallet
     *
     * @deprecated Use createHDWallet() instead
     */
    public function createWalletWithMnemonic(?string $mnemonic = null, int $accountIndex = 0): array
    {
        return $this->createHDWallet($mnemonic);
    }

    /**
     * Generate BIP39 mnemonic phrase
     *
     * @param int $strength Entropy strength in bits (128, 160, 192, 224, 256)
     * @return string Mnemonic phrase
     * @throws Exception
     */
    private function generateMnemonic(int $strength = 128): string
    {
        if (!in_array($strength, [128, 160, 192, 224, 256])) {
            throw new Exception('Invalid mnemonic strength');
        }

        // Generate random entropy
        $entropy = random_bytes($strength / 8);

        // Load BIP39 wordlist
        $wordlist = $this->getBIP39Wordlist();

        // Convert entropy to mnemonic
        $mnemonic = $this->entropyToMnemonic($entropy, $wordlist);

        return $mnemonic;
    }


    private function getPublicKey(string $privateKey): string
    {
        $privateKey = $this->normalizePrivateKey($privateKey);

        // Use simplito/elliptic-php for secp256k1 operations
        $ec = new \Elliptic\EC('secp256k1');
        
        // Get key pair from private key
        $keyPair = $ec->keyFromPrivate($privateKey, 'hex');
        
        // Get public key in uncompressed format
        $publicKey = $keyPair->getPublic('hex');
        
        // Remove 04 prefix if present (uncompressed format indicator)
        if (substr($publicKey, 0, 2) === '04') {
            $publicKey = substr($publicKey, 2);
        }

        return $publicKey;
    }

    /**
     * Convert public key to Ethereum address
     *
     * @param string $publicKey Public key (hex, without 0x and 04 prefix)
     * @return string Ethereum address (0x...)
     */
    private function publicKeyToAddress(string $publicKey): string
    {
        // Remove 0x prefix if present
        $publicKey = str_replace('0x', '', $publicKey);

        // Remove 04 prefix if present
        if (substr($publicKey, 0, 2) === '04') {
            $publicKey = substr($publicKey, 2);
        }

        // Keccak256 hash
        $hash = Keccak::hash(hex2bin($publicKey), 256);

        // Take last 20 bytes (40 characters)
        $address = '0x' . substr($hash, -40);

        // Return checksummed address
        return $this->toChecksumAddress($address);
    }

    /**
     * Convert private key to address
     *
     * @param string $privateKey Private key (hex, without 0x)
     * @return string Ethereum address (0x...)
     */
    private function privateKeyToAddress(string $privateKey): string
    {
        $publicKey = $this->getPublicKey($privateKey);
        return $this->publicKeyToAddress($publicKey);
    }

    /**
     * Convert address to checksum format (EIP-55)
     *
     * @param string $address Address (0x...)
     * @return string Checksummed address
     */
    private function toChecksumAddress(string $address): string
    {
        $address = strtolower(str_replace('0x', '', $address));
        $hash = Keccak::hash($address, 256);

        $checksum = '0x';
        for ($i = 0; $i < 40; $i++) {
            $checksum .= (intval($hash[$i], 16) > 7)
                ? strtoupper($address[$i])
                : $address[$i];
        }

        return $checksum;
    }

    /**
     * Validate Ethereum address
     *
     * @param string $address Address to validate
     * @return bool
     */
    public function isValidAddress(string $address): bool
    {
        return (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', $address);
    }

    /**
     * Get balance of address
     *
     * @param string $address Ethereum address
     * @return string Balance in native token (DEV/GLMR)
     * @throws Exception
     */
    public function getBalance(string $address): string
    {
        if (!$this->isValidAddress($address)) {
            throw new Exception('Invalid address format');
        }

        $balance = null;
        $error = null;

        $this->web3->eth->getBalance($address, function ($err, $result) use (&$balance, &$error) {
            if ($err !== null) {
                $error = $err;
                return;
            }
            $balance = $result;
        });

        if ($error !== null) {
            throw new Exception('Failed to get balance: ' . $error->getMessage());
        }

        // Convert from Wei to Ether
        return Utils::fromWei($balance, 'ether')->toString();
    }

    /**
     * Get transaction count (nonce) for address
     *
     * @param string $address Ethereum address
     * @param string $block Block parameter (latest, pending, earliest)
     * @return int Nonce
     * @throws Exception
     */
    public function getNonce(string $address, string $block = 'pending'): int
    {
        $nonce = null;
        $error = null;

        $this->web3->eth->getTransactionCount($address, $block, function ($err, $result) use (&$nonce, &$error) {
            if ($err !== null) {
                $error = $err;
                return;
            }
            $nonce = $result;
        });

        if ($error !== null) {
            throw new Exception('Failed to get nonce: ' . $error->getMessage());
        }

        return (int) hexdec($nonce->toString());
    }

    /**
     * Get current gas price
     *
     * @return string Gas price in Wei
     * @throws Exception
     */
    public function getGasPrice(): string
    {
        $gasPrice = null;
        $error = null;

        $this->web3->eth->gasPrice(function ($err, $result) use (&$gasPrice, &$error) {
            if ($err !== null) {
                $error = $err;
                return;
            }
            $gasPrice = $result;
        });

        if ($error !== null) {
            throw new Exception('Failed to get gas price: ' . $error->getMessage());
        }

        return $gasPrice->toString();
    }

    /**
     * Estimate gas for transaction
     *
     * @param array $transaction Transaction parameters
     * @return int Estimated gas
     * @throws Exception
     */
    public function estimateGas(array $transaction): int
    {
        $gas = null;
        $error = null;

        $this->web3->eth->estimateGas($transaction, function ($err, $result) use (&$gas, &$error) {
            if ($err !== null) {
                $error = $err;
                return;
            }
            $gas = $result;
        });

        if ($error !== null) {
            // Return default gas limit on error
            return 300000;
        }

        // Add 20% buffer
        return (int) (hexdec($gas->toString()) * 1.2);
    }

    /**
     * Send native token (DEV/GLMR) using gas wallet
     *
     * @param string $toAddress Recipient address
     * @param string $amount Amount in native token (not Wei)
     * @param int|null $gasLimit Optional gas limit
     * @return string Transaction hash
     * @throws Exception
     */
    public function sendNativeToken(
        string $toAddress,
        string $amount,
        ?int $gasLimit = null
    ): string {
        if (!$this->gasWalletPrivateKey) {
            throw new Exception('Gas wallet not configured');
        }

        if (!$this->isValidAddress($toAddress)) {
            throw new Exception('Invalid recipient address');
        }

        // Convert amount to Wei
        $amountWei = Utils::toWei($amount, 'ether');

        // Build transaction
        $tx = [
            'from' => $this->gasWalletAddress,
            'to' => $toAddress,
            'value' => '0x' . $amountWei->toHex(),
            'gas' => '0x' . dechex($gasLimit ?? 21000),
            'gasPrice' => '0x' . dechex($this->getGasPrice()),
            'nonce' => '0x' . dechex($this->getNonce($this->gasWalletAddress)),
            'chainId' => $this->chainId
        ];

        return $this->signAndSendTransaction($tx, $this->gasWalletPrivateKey);
    }

    /**
     * Sign and send raw transaction
     *
     * @param array $transaction Transaction data
     * @param string $privateKey Private key (without 0x)
     * @return string Transaction hash
     * @throws Exception
     */
    private function signAndSendTransaction(array $transaction, $privateKey): string
    {
        // Normalize private key input (expected as string without 0x)
        $rawPriv = $this->normalizePrivateKey((string)$privateKey);

        // Sign transaction using ethereum-tx
        $rawTx = $this->signTransaction($transaction, $rawPriv);

        // Send raw transaction
        $txHash = null;
        $error = null;

        $this->web3->eth->sendRawTransaction('0x' . $rawTx, function ($err, $result) use (&$txHash, &$error) {
            if ($err !== null) {
                $error = $err;
                return;
            }
            $txHash = $result;
        });

        if ($error !== null) {
            // wipe raw priv before throwing
            $this->secureWipe($rawPriv);
            throw new Exception('Failed to send transaction: ' . $error->getMessage());
        }

        // Wipe sensitive raw private key copy ASAP
        $this->secureWipe($rawPriv);

        return $txHash;
    }

    /**
     * Sign transaction with private key
     *
     * @param array $tx Transaction data
     * @param string $privateKey Private key (without 0x)
     * @return string Signed raw transaction (hex)
     * @throws Exception
     */
    private function signTransaction(array $tx, string $privateKey): string
    {
        $transaction = new Transaction([
            'nonce' => $tx['nonce'],
            'gasPrice' => $tx['gasPrice'],
            'gasLimit' => $tx['gas'],
            'to' => $tx['to'],
            'value' => $tx['value'],
            'data' => $tx['data'] ?? '0x',
            'chainId' => $tx['chainId']
        ]);

        $signedTx = $transaction->sign($privateKey);

        return $signedTx;
    }

    /**
     * Encode ERC20 transfer(address,uint256) call data
     */
    private function encodeErc20TransferData(string $toAddress, string $amountWei): string
    {
        // Method selector
        $methodId = substr(Keccak::hash('transfer(address,uint256)', 256), 0, 8);
        $to = strtolower(str_replace('0x', '', $toAddress));
        $amount = strtolower(str_replace('0x', '', $amountWei));

        // Pad to 32 bytes
        $toPadded = str_pad($to, 64, '0', STR_PAD_LEFT);
        $amountPadded = str_pad($amount, 64, '0', STR_PAD_LEFT);

        return '0x' . $methodId . $toPadded . $amountPadded;
    }

    /**
     * Get ERC20 balance using minimal ABI (balanceOf)
     */
    public function getERC20Balance(string $tokenAddress, string $holder): array
    {
        if (!$this->isValidAddress($tokenAddress) || !$this->isValidAddress($holder)) {
            throw new Exception('Invalid token or holder address');
        }

        $contract = new Contract($this->web3->provider, '[{"constant":true,"inputs":[{"name":"account","type":"address"}],"name":"balanceOf","outputs":[{"name":"","type":"uint256"}],"type":"function"},{"constant":true,"inputs":[],"name":"decimals","outputs":[{"name":"","type":"uint8"}],"type":"function"},{"constant":true,"inputs":[],"name":"symbol","outputs":[{"name":"","type":"string"}],"type":"function"}]');
        $contractAt = $contract->at($tokenAddress);

        // Fetch balance
        $raw = null; $rawError = null;
        $contractAt->call('balanceOf', $holder, function ($err, $res) use (&$raw, &$rawError) {
            if ($err !== null) { $rawError = $err; return; }
            if (isset($res[0])) { $raw = $res[0]->toString(); }
        });
        
        // Fetch decimals
        $decimals = null; $decimalsError = null;
        $contractAt->call('decimals', function ($err, $res) use (&$decimals, &$decimalsError) {
            if ($err !== null) { $decimalsError = $err; return; }
            if (isset($res[0])) { $decimals = (int)$res[0]->toString(); }
        });
        
        // Fetch symbol
        $symbol = null; $symbolError = null;
        $contractAt->call('symbol', function ($err, $res) use (&$symbol, &$symbolError) {
            if ($err !== null) { $symbolError = $err; return; }
            if (isset($res[0])) { $symbol = (string)$res[0]; }
        });

        // Wait for all callbacks to complete with timeout
        $timeoutSeconds = 10;
        $start = microtime(true);
        while (($raw === null || $decimals === null || $symbol === null) && 
               (microtime(true) - $start) < $timeoutSeconds) {
            usleep(10000); // 10ms
            // Break early if all completed
            if ($raw !== null && $decimals !== null && $symbol !== null) break;
        }

        // Check for errors
        if ($rawError !== null) {
            throw new Exception('Failed to get token balance: ' . 
                (method_exists($rawError, 'getMessage') ? $rawError->getMessage() : (string)$rawError));
        }
        if ($decimalsError !== null) {
            throw new Exception('Failed to get token decimals: ' . 
                (method_exists($decimalsError, 'getMessage') ? $decimalsError->getMessage() : (string)$decimalsError));
        }
        if ($symbolError !== null) {
            throw new Exception('Failed to get token symbol: ' . 
                (method_exists($symbolError, 'getMessage') ? $symbolError->getMessage() : (string)$symbolError));
        }

        // Use fallback defaults if still null after timeout
        if ($raw === null) $raw = '0';
        if ($decimals === null) $decimals = 18;
        if ($symbol === null) $symbol = 'TOKEN';

        $formatted = bcdiv($raw, bcpow('10', (string)$decimals, 0), $decimals);
        return [
            'raw' => $raw,
            'formatted' => rtrim(rtrim($formatted, '0'), '.'),
            'decimals' => $decimals,
            'symbol' => $symbol,
        ];
    }

    /**
     * Transfer ERC20 token using a private key (sender pays gas).
     * $amount can be a decimal string, will be converted to wei using token decimals.
     */
    
    public function transferERC20(
        string $fromPrivateKey,
        string $tokenAddress,
        string $toAddress,
        string $amount,
        ?int $gasLimit = null
    ): string {
        $fromPriv = $this->normalizePrivateKey($fromPrivateKey);
        $from = $this->privateKeyToAddress($fromPriv);

        if (!$this->isValidAddress($tokenAddress) || !$this->isValidAddress($toAddress)) {
            throw new Exception('Invalid token or recipient address');
        }

        // Fetch decimals to parse amount
        $ercBal = $this->getERC20Balance($tokenAddress, $from);
        $decimals = $ercBal['decimals'];
        $amountWei = $this->toTokenWei($amount, $decimals);

        $gasPrice = $this->getGasPrice();
        $nonce = $this->getNonce($from);
        $limit = $gasLimit ?? 80000; // typical ERC20 transfer

        $tx = [
            'from' => $from,
            'to' => $tokenAddress,
            'value' => '0x0',
            'gas' => '0x' . dechex($limit),
            'gasPrice' => '0x' . dechex((int)$gasPrice),
            'nonce' => '0x' . dechex($nonce),
            'data' => $this->encodeErc20TransferData($toAddress, '0x' . dechex((int)$amountWei)),
            'chainId' => $this->chainId
        ];

        return $this->signAndSendTransaction($tx, $fromPriv);
    }

    /**
     * Sweep entire native balance from a private key to destination, keeping gas.
     */
    public function sweepNative(string $fromPrivateKey, string $toAddress, ?int $gasLimit = null): array
    {
        $fromPriv = $this->normalizePrivateKey($fromPrivateKey);
        $from = $this->privateKeyToAddress($fromPriv);
        if (!$this->isValidAddress($toAddress)) throw new Exception('Invalid destination');

        $balanceEth = $this->getBalance($from);
        $gasPrice = (int)$this->getGasPrice();
        $limit = $gasLimit ?? 21000;
        $feeWei = $gasPrice * $limit;
        $balanceWei = (int)Utils::toWei($balanceEth, 'ether')->toString();
        $amountWei = $balanceWei - $feeWei;
        if ($amountWei <= 0) {
            return ['success' => false, 'reason' => 'Insufficient balance for gas', 'from' => $from, 'to' => $toAddress];
        }

        $tx = [
            'from' => $from,
            'to' => $toAddress,
            'value' => '0x' . dechex($amountWei),
            'gas' => '0x' . dechex($limit),
            'gasPrice' => '0x' . dechex($gasPrice),
            'nonce' => '0x' . dechex($this->getNonce($from)),
            'chainId' => $this->chainId
        ];
        $txHash = $this->signAndSendTransaction($tx, $fromPriv);
        return ['success' => true, 'txHash' => $txHash, 'from' => $from, 'to' => $toAddress, 'amountWei' => '0x' . dechex($amountWei)];
    }

    /**
     * Sweep entire ERC20 balance from a private key to destination.
     */
    public function sweepERC20(string $fromPrivateKey, string $tokenSymbolOrAddress, string $toAddress, ?int $gasLimit = null): array
    {
        $fromPriv = $this->normalizePrivateKey($fromPrivateKey);
        $from = $this->privateKeyToAddress($fromPriv);
        if (!$this->isValidAddress($toAddress)) throw new Exception('Invalid destination');

        $tokenAddress = $this->isValidAddress($tokenSymbolOrAddress)
            ? $tokenSymbolOrAddress
            : $this->getTokenAddress($tokenSymbolOrAddress);

        $bal = $this->getERC20Balance($tokenAddress, $from);
        if (bccomp($bal['raw'], '0') <= 0) {
            return ['success' => false, 'reason' => 'Zero token balance', 'from' => $from, 'to' => $toAddress];
        }

        $txHash = $this->transferERC20($fromPriv, $tokenAddress, $toAddress, $bal['formatted'], $gasLimit);
        return ['success' => true, 'txHash' => $txHash, 'from' => $from, 'to' => $toAddress, 'amount' => $bal['formatted'], 'symbol' => $bal['symbol']];
    }

    /**
     * Wait for transaction receipt
     *
     * @param string $txHash Transaction hash
     * @param int $maxAttempts Maximum attempts
     * @param int $intervalSeconds Seconds between attempts
     * @return array Transaction receipt
     * @throws Exception
     */
    public function waitForTransaction(
        string $txHash,
        int $maxAttempts = 60,
        int $intervalSeconds = 2
    ): array {
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            $receipt = null;
            $error = null;

            $this->web3->eth->getTransactionReceipt($txHash, function ($err, $result) use (&$receipt, &$error) {
                if ($err !== null) {
                    $error = $err;
                    return;
                }
                $receipt = $result;
            });

            if ($receipt !== null) {
                return [
                    'success' => $receipt->status === '0x1',
                    'blockNumber' => hexdec($receipt->blockNumber),
                    'gasUsed' => hexdec($receipt->gasUsed),
                    'contractAddress' => $receipt->contractAddress ?? null,
                    'logs' => $receipt->logs ?? [],
                    'receipt' => $receipt
                ];
            }

            sleep($intervalSeconds);
            $attempts++;
        }

        throw new Exception("Transaction timeout: $txHash");
    }

    /**
     * Get network information
     *
     * @return array Network configuration
     */
    public function getNetworkInfo(): array
    {
        $cfg = self::NETWORKS[$this->network][$this->chain];
        // Include env/chain keys for callers
        return $cfg + ['env' => $this->network, 'chain' => $this->chain];
    }

    /**
     * Token configurations for Moonbeam mainnet and Moonbase testnet
     * - USDC on mainnet, aUSDC on testnet
     * - USDT on mainnet only
     */
    public function getTokenConfig(?string $networkKey = null): array
    {
        // networkKey can be 'mainnet:ethereum' or 'testnet:moonbeam'. If null, use current.
        if ($networkKey === null) {
            $chainId = $this->chainId;
        } else {
            $parts = explode(':', $networkKey);
            if (count($parts) !== 2) {
                throw new Exception("networkKey must be 'env:chain', e.g., 'mainnet:ethereum'");
            }
            [$env, $chain] = $parts;
            if (!isset(self::NETWORKS[$env][$chain])) {
                throw new Exception("Unknown networkKey '$networkKey'");
            }
            $chainId = self::NETWORKS[$env][$chain]['chainId'];
        }
        $isMoonbase = ($chainId === 1287);
        $isMoonbeam = ($chainId === 1284);
        $isEth = ($chainId === 1);
        $isBsc = ($chainId === 56);

        // Default: empty entries
        $tokens = [
            'USDC'  => ['address' => null, 'decimals' => 6, 'symbol' => 'USDC'],
            'USDT'  => ['address' => null, 'decimals' => 6, 'symbol' => 'USDT'],
            'AUSDC' => ['address' => null, 'decimals' => 6, 'symbol' => 'AUSDC'], // Moonbeam-wrapped USDC (e.g., aUSDC on Moonbase)
        ];

        if ($isMoonbase) {
            // Testnet aUSDC (as used in repo)
            // aUSDC test token (Moonbase)
            $tokens['AUSDC']['address'] = '0xD1633F7Fb3d716643125d6415d4177bC36b7186b';
            $tokens['AUSDC']['symbol'] = 'AUSDC';

            $tokens['USDT']['address'] = null; // not available on testnet
        } elseif ($isMoonbeam) {
            // Moonbeam mainnet addresses as used in repo
            $tokens['USDC']['address'] = '0x818ec0A7Fe18Ff94269904fCED6AE3DaE6d6dC0b';
            $tokens['USDT']['address'] = '0xeFAeeE334F0Fd1712f9a8cc375f427D9Cdd40d73';
        } elseif ($isEth) {
            // Ethereum official
            $tokens['USDC']['address'] = '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606e48';
            $tokens['USDT']['address'] = '0xdAC17F958D2ee523a2206206994597C13D831ec7';
        } elseif ($isBsc) {
            // BSC official Binance-Peg tokens
            $tokens['USDC']['address'] = '0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d';
            $tokens['USDT']['address'] = '0x55d398326f99059fF775485246999027B3197955';
        }

        return $tokens;
    }

    public function getTokenAddress(string $symbol, ?string $networkKey = null): string
    {
        $sym = strtoupper($symbol);
        $cfg = $this->getTokenConfig($networkKey);
        if (!isset($cfg[$sym])) {
            throw new Exception("Unsupported token: $symbol");
        }
        $addr = $cfg[$sym]['address'];
        if (!$addr) {
            throw new Exception("$sym is not available on this network");
        }
        return $addr;
    }

    /**
     * Get block number
     *
     * @return int Current block number
     * @throws Exception
     */
    public function getBlockNumber(): int
    {
        $blockNumber = null;
        $error = null;

        $this->web3->eth->blockNumber(function ($err, $result) use (&$blockNumber, &$error) {
            if ($err !== null) {
                $error = $err;
                return;
            }
            $blockNumber = $result;
        });

        if ($error !== null) {
            throw new Exception('Failed to get block number: ' . $error->getMessage());
        }

        return hexdec($blockNumber->toString());
    }

    /**
     * Normalize private key format
     *
     * @param string $privateKey Private key
     * @return string Normalized private key (without 0x)
     */
    private function normalizePrivateKey(string $privateKey): string
    {
        return str_replace('0x', '', $privateKey);
    }

    /**
     * Convert token decimal amount to wei integer string
     */
    public function toTokenWei(string $amount, int $decimals): string
    {
        return bcmul($amount, bcpow('10', (string)$decimals), 0);
    }

    /**
     * Convenience: derive EVM private key at index (0x-prefixed)
     */
    public function deriveEvmPrivateKeyByIndex(int $index = 0): string
    {
        $evm = $this->deriveAddress('evm', $index);
        return $evm['privateKey'];
    }

    /**
     * Convenience: list a range of derived EVM addresses and keys
     */
    public function listDerivedEvmAddresses(int $start = 0, int $count = 5): array
    {
        $out = [];
        for ($i = $start; $i < $start + $count; $i++) {
            $evm = $this->deriveAddress('evm', $i);
            $out[] = [
                'index' => $i,
                'address' => $evm['address'],
                'publicKey' => $evm['publicKey'],
                'privateKey' => $evm['privateKey'],
                'path' => $evm['path']
            ];
        }
        return $out;
    }

    /**
     * Securely wipe a string variable from memory when possible.
     * Uses sodium_memzero when available, otherwise overwrites the string.
     * Note: in PHP we cannot absolutely guarantee memory clearing, but this
     * reduces the lifetime of sensitive data in userland memory.
     *
     * @param string|null &$var
     */
    private function secureWipe(?string &$var): void
    {
        if (!isset($var)) {
            return;
        }

        if (function_exists('sodium_memzero')) {
            try {
                sodium_memzero($var);
            } catch (\Throwable $e) {
                // fallback to overwrite below
                $var = str_repeat("\0", strlen($var));
            }
        } else {
            // best-effort overwrite
            $var = str_repeat("\0", strlen($var));
        }

        // unset reference to shorten lifetime
        $var = null;
    }

    // Helper methods for BIP39/BIP44
    private function getBIP39Wordlist(): array
    {
        // BIP39 wordlist here for convenience.
        return [
            "abandon","ability","able","about","above","absent","absorb","abstract","absurd","abuse",
            "access","accident","account","accuse","achieve","acid","acoustic","acquire","across","act",
            "action","actor","actress","actual","adapt","add","addict","address","adjust","admit",
            "adult","advance","advice","aerobic","affair","afford","afraid","again","age","agent",
            "agree","ahead","aim","air","airport","aisle","alarm","album","alcohol","alert",
            "alien","all","alley","allow","almost","alone","alpha","already","also","alter",
            "always","amateur","amazing","among","amount","amuse","analyst","anchor","ancient","anger",
            "angle","angry","animal","ankle","announce","annual","another","answer","antenna","antique",
            "anxiety","any","apart","apology","appear","apple","approve","april","arch","arctic",
            "area","arena","argue","arm","armed","armor","army","around","arrange","arrest",
            "arrive","arrow","art","artefact","artist","artwork","ask","aspect","assault","asset",
            "assist","assume","asthma","athlete","atom","attack","attend","attitude","attract","auction",
            "audit","august","aunt","author","auto","autumn","average","avocado","avoid","awake",
            "aware","away","awesome","awful","awkward","axis","baby","bachelor","bacon","badge",
            "bag","balance","balcony","ball","bamboo","banana","banner","bar","barely","bargain",
            "barrel","base","basic","basket","battle","beach","bean","beauty","because","become",
            "beef","before","begin","behave","behind","believe","below","belt","bench","benefit",
            "best","betray","better","between","beyond","bicycle","bid","bike","bind","biology",
            "bird","birth","bitter","black","blade","blame","blanket","blast","bleak","bless",
            "blind","blood","blossom","blouse","blue","blur","blush","board","boat","body",
            "boil","bold","bolt","bomb","bone","bonus","book","boost","border","boring",
            "borrow","boss","bottom","bounce","box","boy","bracket","brain","brand","brass",
            "brave","bread","breeze","brick","bridge","brief","bright","bring","brisk","broccoli",
            "broken","bronze","broom","brother","brown","brush","bubble","buddy","budget","buffalo",
            "build","bulb","bulk","bullet","bundle","bunker","burden","burger","burst","bus",
            "business","busy","butter","buyer","buzz","cabbage","cabin","cable","cactus","cage",
            "cake","call","calm","camera","camp","can","canal","cancel","candy","cannon",
            "canoe","canvas","canyon","capable","capital","captain","car","carbon","card","cargo",
            "carpet","carry","cart","case","cash","casino","castle","casual","cat","catalog",
            "catch","category","cattle","caught","cause","caution","cave","ceiling","celery","cement",
            "census","century","cereal","certain","chair","chalk","champion","change","chaos","chapter",
            "charge","chase","chat","cheap","check","cheese","chef","cherry","chest","chicken",
            "chief","child","chimney","choice","choose","chronic","chuckle","chunk","churn","cigar",
            "cinnamon","circle","citizen","city","civil","claim","clap","clarify","claw","clay",
            "clean","clerk","clever","click","client","cliff","climb","clinic","clip","clock",
            "clog","close","cloth","cloud","clown","club","clump","cluster","clutch","coach",
            "coast","coconut","code","coffee","coil","coin","collect","color","column","combine",
            "come","comfort","comic","common","company","concert","conduct","confirm","congress","connect",
            "consider","control","convince","cook","cool","copper","copy","coral","core","corn",
            "correct","cost","cotton","couch","country","couple","course","cousin","cover","coyote",
            "crack","cradle","craft","cram","crane","crash","crater","crawl","crazy","cream",
            "credit","creek","crew","cricket","crime","crisp","critic","crop","cross","crouch",
            "crowd","crucial","cruel","cruise","crumble","crunch","crush","cry","crystal","cube",
            "culture","cup","cupboard","curious","current","curtain","curve","cushion","custom","cute",
            "cycle","dad","damage","damp","dance","danger","daring","dash","daughter","dawn",
            "day","deal","debate","debris","decade","december","decide","decline","decorate","decrease",
            "deer","defense","define","defy","degree","delay","deliver","demand","demise","denial",
            "dentist","deny","depart","depend","deposit","depth","deputy","derive","describe","desert",
            "design","desk","despair","destroy","detail","detect","develop","device","devote","diagram",
            "dial","diamond","diary","dice","diesel","diet","differ","digital","dignity","dilemma",
            "dinner","dinosaur","direct","dirt","disagree","discover","disease","dish","dismiss","disorder",
            "display","distance","divert","divide","divorce","dizzy","doctor","document","dog","doll",
            "dolphin","domain","donate","donkey","donor","door","dose","double","dove","draft",
            "dragon","drama","drastic","draw","dream","dress","drift","drill","drink","drip",
            "drive","drop","drum","dry","duck","dumb","dune","during","dust","dutch",
            "duty","dwarf","dynamic","eager","eagle","early","earn","earth","easily","east",
            "easy","echo","ecology","economy","edge","edit","educate","effort","egg","eight",
            "either","elbow","elder","electric","elegant","element","elephant","elevator","elite","else",
            "embark","embody","embrace","emerge","emotion","employ","empower","empty","enable","enact",
            "end","endless","endorse","enemy","energy","enforce","engage","engine","enhance","enjoy",
            "enlist","enough","enrich","enroll","ensure","enter","entire","entry","envelope","episode",
            "equal","equip","era","erase","erode","erosion","error","erupt","escape","essay",
            "essence","estate","eternal","ethics","evidence","evil","evoke","evolve","exact","example",
            "excess","exchange","excite","exclude","excuse","execute","exercise","exhaust","exhibit","exile",
            "exist","exit","exotic","expand","expect","expire","explain","expose","express","extend",
            "extra","eye","eyebrow","fabric","face","faculty","fade","faint","faith","fall",
            "false","fame","family","famous","fan","fancy","fantasy","farm","fashion","fat",
            "fatal","father","fatigue","fault","favorite","feature","february","federal","fee","feed",
            "feel","female","fence","festival","fetch","fever","few","fiber","fiction","field",
            "figure","file","film","filter","final","find","fine","finger","finish","fire",
            "firm","first","fiscal","fish","fit","fitness","fix","flag","flame","flash",
            "flat","flavor","flee","flight","flip","float","flock","floor","flower","fluid",
            "flush","fly","foam","focus","fog","foil","fold","follow","food","foot",
            "force","forest","forget","fork","fortune","forum","forward","fossil","foster","found",
            "fox","fragile","frame","frequent","fresh","friend","fringe","frog","front","frost",
            "frown","frozen","fruit","fuel","fun","funny","furnace","fury","future","gadget",
            "gain","galaxy","gallery","game","gap","garage","garbage","garden","garlic","garment",
            "gas","gasp","gate","gather","gauge","gaze","general","genius","genre","gentle",
            "genuine","gesture","ghost","giant","gift","giggle","ginger","giraffe","girl","give",
            "glad","glance","glare","glass","glide","glimpse","globe","gloom","glory","golf",
            "good","goose","gorilla","gospel","gossip","govern","gown","grab","grace","grain",
            "grant","grape","grass","gravity","great","green","grid","grief","grit","grocery",
            "group","grow","grunt","guard","guess","guide","guilt","guitar","gun","gym",
            "habit","hair","half","hammer","hamster","hand","happy","harbor","hard","harsh",
            "harvest","hat","have","hawk","hazard","head","health","heart","heavy","hedgehog",
            "height","hello","helmet","help","hen","hero","hidden","high","hill","hint",
            "hip","hire","history","hobby","hockey","hold","hole","holiday","hollow","home",
            "honey","honor","hood","hope","horn","horror","horse","hospital","host","hotel",
            "hour","hover","hub","huge","human","humble","humor","hundred","hungry","hunt",
            "hurdle","hurry","hurt","husband","hybrid","ice","icon","idea","identify","idle",
            "ignore","ill","illegal","illness","image","imitate","immense","immune","impact","impose",
            "improve","impulse","inch","include","income","increase","index","indicate","indoor","industry",
            "infant","inflict","inform","inhale","inherit","initial","inject","injury","inmate","inner",
            "innocent","input","inquiry","insane","insect","inside","inspire","install","intact","interest",
            "into","invest","invite","involve","iron","island","isolate","issue","item","ivory",
            "jacket","jaguar","jar","jazz","jealous","jeans","jelly","jewel","job","join",
            "joke","journey","joy","judge","juice","jump","jungle","junior","junk","just",
            "kangaroo","keen","keep","ketchup","key","kick","kid","kidney","kind","kingdom",
            "kiss","kit","kitchen","kite","kitten","kiwi","knee","knife","knock","know",
            "lab","label","labor","ladder","lady","lake","lamp","language","laptop","large",
            "later","latin","laugh","laundry","lava","law","lawn","lawsuit","layer","lazy",
            "leader","leaf","learn","leave","lecture","left","leg","legal","legend","leisure",
            "lemon","lend","length","lens","leopard","lesson","letter","level","liar","liberty",
            "library","license","life","lift","light","like","limb","limit","link","lion",
            "liquid","list","little","live","lizard","load","loan","lobster","local","lock",
            "logic","lonely","long","loop","lottery","loud","lounge","love","loyal","lucky",
            "luggage","lumber","lunar","lunch","luxury","lyrics","machine","mad","magic","magnet",
            "maid","mail","main","major","make","mammal","man","manage","mandate","mango",
            "mansion","manual","maple","marble","march","margin","marine","market","marriage","mask",
            "mass","master","match","material","math","matrix","matter","maximum","maze","meadow",
            "mean","measure","meat","mechanic","medal","media","melody","melt","member","memory",
            "mention","menu","mercy","merge","merit","merry","mesh","message","metal","method",
            "middle","midnight","milk","million","mimic","mind","minimum","minor","minute","miracle",
            "mirror","misery","miss","mistake","mix","mixed","mixture","mobile","model","modify",
            "mom","moment","monitor","monkey","monster","month","moon","moral","more","morning",
            "mosquito","mother","motion","motor","mountain","mouse","move","movie","much","muffin",
            "mule","multiply","muscle","museum","mushroom","music","must","mutual","myself","mystery",
            "myth","naive","name","napkin","narrow","nasty","nation","nature","near","neck",
            "need","negative","neglect","neither","nephew","nerve","nest","net","network","neutral",
            "never","news","next","nice","night","noble","noise","nominee","noodle","normal",
            "north","nose","notable","note","nothing","notice","novel","now","nuclear","number",
            "nurse","nut","oak","obey","object","oblige","obscure","observe","obtain","obvious",
            "occur","ocean","october","odor","off","offer","office","often","oil","okay",
            "old","olive","olympic","omit","once","one","onion","online","only","open",
            "opera","opinion","oppose","option","orange","orbit","orchard","order","ordinary","organ",
            "orient","original","orphan","ostrich","other","outdoor","outer","output","outside","oval",
            "oven","over","own","owner","oxygen","oyster","ozone","pact","paddle","page",
            "pair","palace","palm","panda","panel","panic","panther","paper","parade","parent",
            "park","parrot","party","pass","patch","path","patient","patrol","pattern","pause",
            "pave","payment","peace","peanut","pear","peasant","pelican","pen","penalty","pencil",
            "people","pepper","perfect","permit","person","pet","phone","photo","phrase","physical",
            "piano","picnic","picture","piece","pig","pigeon","pill","pilot","pink","pioneer",
            "pipe","pistol","pitch","pizza","place","planet","plastic","plate","play","please",
            "pledge","pluck","plug","plunge","poem","poet","point","polar","pole","police",
            "pond","pony","pool","popular","portion","position","possible","post","potato","pottery",
            "poverty","powder","power","practice","praise","predict","prefer","prepare","present","pretty",
            "prevent","price","pride","primary","print","priority","prison","private","prize","problem",
            "process","produce","profit","program","project","promote","proof","property","prosper","protect",
            "proud","provide","public","pudding","pull","pulp","pulse","pumpkin","punch","pupil",
            "puppy","purchase","purity","purpose","purse","push","put","puzzle","pyramid","quality",
            "quantum","quarter","question","quick","quit","quiz","quote","rabbit","raccoon","race",
            "rack","radar","radio","rail","rain","raise","rally","ramp","ranch","random",
            "range","rapid","rare","rate","rather","raven","raw","razor","ready","real",
            "reason","rebel","rebuild","recall","receive","recipe","record","recycle","reduce","reflect",
            "reform","refuse","region","regret","regular","reject","relax","release","relief","rely",
            "remain","remember","remind","remove","render","renew","rent","reopen","repair","repeat",
            "replace","report","require","rescue","resemble","resist","resource","response","result","retire",
            "retreat","return","reunion","reveal","review","reward","rhythm","rib","ribbon","rice",
            "rich","ride","ridge","rifle","right","rigid","ring","riot","ripple","risk",
            "ritual","rival","river","road","roast","robot","robust","rocket","romance","roof",
            "rookie","room","rose","rotate","rough","round","route","royal","rubber","rude",
            "rug","rule","run","runway","rural","sad","saddle","sadness","safe","sail",
            "salad","salmon","salon","salt","salute","same","sample","sand","satisfy","satoshi",
            "sauce","sausage","save","say","scale","scan","scare","scatter","scene","scheme",
            "school","science","scissors","scorpion","scout","scrap","screen","script","scrub","sea",
            "search","season","seat","second","secret","section","security","seed","seek","segment",
            "select","sell","seminar","senior","sense","sentence","series","service","session","settle",
            "setup","seven","shadow","shaft","shallow","share","shed","shell","sheriff","shield",
            "shift","shine","ship","shiver","shock","shoe","shoot","shop","short","shoulder",
            "shove","shrimp","shrug","shuffle","shy","sibling","sick","side","siege","sight",
            "sign","silent","silk","silly","silver","similar","simple","since","sing","siren",
            "sister","situate","six","size","skate","sketch","ski","skill","skin","skirt",
            "skull","slab","slam","sleep","slender","slice","slide","slight","slim","slogan",
            "slot","slow","slush","small","smart","smile","smoke","smooth","snack","snake",
            "snap","sniff","snow","soap","soccer","social","sock","soda","soft","solar",
            "soldier","solid","solution","solve","someone","song","soon","sorry","sort","soul",
            "sound","soup","source","south","space","spare","spatial","spawn","speak","special",
            "speed","spell","spend","sphere","spice","spider","spike","spin","spirit","split",
            "spoil","sponsor","spoon","sport","spot","spray","spread","spring","spy","square",
            "squeeze","squirrel","stable","stadium","staff","stage","stairs","stamp","stand","start",
            "state","stay","steak","steel","stem","step","stereo","stick","still","sting",
            "stock","stomach","stone","stool","story","stove","strategy","street","strike","strong",
            "struggle","student","stuff","stumble","style","subject","submit","subway","success","such",
            "sudden","suffer","sugar","suggest","suit","summer","sun","sunny","sunset","super",
            "supply","supreme","sure","surface","surge","surprise","surround","survey","suspect","sustain",
            "swallow","swamp","swap","swarm","swear","sweet","swift","swim","swing","switch",
            "sword","symbol","symptom","syrup","system","table","tackle","tag","tail","talent",
            "talk","tank","tape","target","task","taste","tattoo","taxi","teach","team",
            "tell","ten","tenant","tennis","tent","term","test","text","thank","that",
            "theme","then","theory","there","they","thing","this","thought","three","thrive",
            "throw","thumb","thunder","ticket","tide","tiger","tilt","timber","time","tiny",
            "tip","tired","tissue","title","toast","tobacco","today","toddler","toe","together",
            "toilet","token","tomato","tomorrow","tone","tongue","tonight","tool","tooth","top",
            "topic","topple","torch","tornado","tortoise","toss","total","tourist","toward","tower",
            "town","toy","track","trade","traffic","tragic","train","transfer","trap","trash",
            "travel","tray","treat","tree","trend","trial","tribe","trick","trigger","trim",
            "trip","trophy","trouble","truck","true","truly","trumpet","trust","truth","try",
            "tube","tuition","tumble","tuna","tunnel","turkey","turn","turtle","twelve","twenty",
            "twice","twin","twist","two","type","typical","ugly","umbrella","unable","unaware",
            "uncle","uncover","under","undo","unfair","unfold","unhappy","uniform","unique","unit",
            "universe","unknown","unlock","until","unusual","unveil","update","upgrade","uphold","upon",
            "upper","upset","urban","urge","usage","use","used","useful","useless","usual",
            "utility","vacant","vacuum","vague","valid","valley","valve","van","vanish","vapor",
            "various","vast","vault","vehicle","velvet","vendor","venture","venue","verb","verify",
            "version","very","vessel","veteran","viable","vibrant","vicious","victory","video","view",
            "village","vintage","violin","virtual","virus","visa","visit","visual","vital","vivid",
            "vocal","voice","void","volcano","volume","vote","voyage","wage","wagon","wait",
            "walk","wall","walnut","want","warfare","warm","warrior","wash","wasp","waste",
            "water","wave","way","wealth","weapon","wear","weasel","weather","web","wedding",
            "weekend","weird","welcome","west","wet","whale","what","wheat","wheel","when",
            "where","whip","whisper","wide","width","wife","wild","will","win","window",
            "wine","wing","wink","winner","winter","wire","wisdom","wise","wish","witness",
            "wolf","woman","wonder","wood","wool","word","work","world","worry","worth",
            "wrap","wreck","wrestle","wrist","write","wrong","yard","year","yellow","you",
            "young","youth","zebra","zero","zone","zoo"
        ];
    }

    private function entropyToMnemonic(string $entropy, array $wordlist): string
    {
        $entropyBin = unpack('C*', $entropy);
        $entropyBits = '';
        foreach ($entropyBin as $byte) {
            $entropyBits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $entropyLength = strlen($entropyBits);
        if ($entropyLength % 32 !== 0) {
            throw new Exception('Invalid entropy length');
        }

        // Checksum: first ENT/32 bits of SHA256(entropy)
        $hash = hash('sha256', $entropy, true);
        $hashBin = unpack('C*', $hash);
        $hashBits = '';
        foreach ($hashBin as $byte) {
            $hashBits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $checksumLength = $entropyLength / 32;
        $checksum = substr($hashBits, 0, $checksumLength);

        $bits = $entropyBits . $checksum;

        // Split into groups of 11 bits
        $words = [];
        $chunks = str_split($bits, 11);
        foreach ($chunks as $chunk) {
            $index = bindec($chunk);
            if (!isset($wordlist[$index])) {
                throw new Exception('Wordlist missing index: ' . $index);
            }
            $words[] = $wordlist[$index];
        }

        return implode(' ', $words);
    }

    private function validateMnemonic(string $mnemonic): bool
    {
        $wordlist = $this->getBIP39Wordlist();
        $words = preg_split('/\s+/', trim($mnemonic));
        $wordCount = count($words);
        if (!in_array($wordCount, [12,15,18,21,24])) {
            return false;
        }

        // Build bits from words
        $bits = '';
        foreach ($words as $w) {
            $index = array_search($w, $wordlist, true);
            if ($index === false) {
                return false;
            }
            $bits .= str_pad(decbin($index), 11, '0', STR_PAD_LEFT);
        }

        $checksumLength = $wordCount * 11 / 33; // ENT/32
        $entropyLength = $wordCount * 11 - $checksumLength;

        $entropyBits = substr($bits, 0, $entropyLength);
        $checksumBits = substr($bits, $entropyLength);

        // Convert entropy bits to bytes
        $entropy = '';
        foreach (str_split($entropyBits, 8) as $byte) {
            $entropy .= chr(bindec($byte));
        }

        $hash = hash('sha256', $entropy, true);
        $hashBits = '';
        foreach (unpack('C*', $hash) as $b) {
            $hashBits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }

        $expectedChecksum = substr($hashBits, 0, $checksumLength);
        return $expectedChecksum === $checksumBits;
    }


    private function mnemonicToSeed(string $mnemonic, string $passphrase = ''): string
    {
        if (class_exists('Normalizer')) {
            $mnemonic = \Normalizer::normalize($mnemonic, \Normalizer::FORM_KD) ?: $mnemonic;
            $passphrase = \Normalizer::normalize($passphrase, \Normalizer::FORM_KD) ?: $passphrase;
        }

        $salt = 'mnemonic' . $passphrase;
        $seed = hash_pbkdf2('sha512', $mnemonic, $salt, 2048, 64, true);
        return $seed;
    }

    public function getWeb3(): Web3
    {
        return $this->web3;
    }

    public function getGasWalletPrivateKey(): ?string
    {
        return $this->gasWalletPrivateKey;
    }

    public function getGasWalletAddress(): ?string
    {
        return $this->gasWalletAddress;
    }

    /**
     * Generate a deterministic child address from master private key using identifier
     * One master private key can generate infinite child addresses
     * The master key can spend from any child address by using the same identifier
     *
     * @param string $masterPrivateKey Master private key (without 0x)
     * @param string $identifier Unique identifier (orderId, customerId, invoiceId, etc.)
     * @param array $metadata Additional tracking data
     * @return array Child address details
     * @throws Exception
     */
    public function generatePaymentAddress(
        string $masterPrivateKey,
        string $identifier,
        array $metadata = []
    ): array {
        if (empty($identifier)) {
            throw new Exception('Identifier is required (e.g., ORDER-12345, CUST-789)');
        }

        $masterPrivateKey = $this->normalizePrivateKey($masterPrivateKey);

        if (strlen($masterPrivateKey) !== 64) {
            throw new Exception('Invalid master private key length');
        }

        // Derive child private key from master key + identifier
        // Formula: childKey = HMAC-SHA256(masterKey, identifier)
        $childKeySeed = hash_hmac('sha256', $identifier, hex2bin($masterPrivateKey), true);
        $childPrivateKey = hash('sha256', $childKeySeed);

        // Generate address from child private key
        $childPublicKey = $this->getPublicKey($childPrivateKey);
        $childAddress = $this->publicKeyToAddress($childPublicKey);

        return [
            'address' => $childAddress,
            'publicKey' => '0x' . $childPublicKey,
            'privateKey' => '0x' . $childPrivateKey, // Derived child private key
            'identifier' => $identifier,
            'derivationMethod' => 'HMAC-SHA256',
            'chain' => 'evm',
            'createdAt' => date('Y-m-d H:i:s'),
            'purpose' => 'payment_collection',
            'network' => $this->network,
            'chainId' => $this->chainId,
            'metadata' => $metadata,
            'note' => 'Child address derived from identifier. Use same identifier to regenerate key and spend.'
        ];
    }

    /**
     * Get child private key from master key and identifier
     * Regenerate the private key needed to spend from a child address
     * Use the SAME identifier you used when generating the address
     *
     * @param string $masterPrivateKey Master private key
     * @param string $identifier The same identifier used when generating (e.g., ORDER-12345)
     * @return string Child private key (with 0x prefix)
     * @throws Exception
     */
    public function getPrivateKeyForAddress(string $masterPrivateKey, string $identifier): string
    {
        if (empty($identifier)) {
            throw new Exception('Identifier is required');
        }

        $masterPrivateKey = $this->normalizePrivateKey($masterPrivateKey);

        if (strlen($masterPrivateKey) !== 64) {
            throw new Exception('Invalid master private key length');
        }

        // Regenerate child private key using same derivation
        $childKeySeed = hash_hmac('sha256', $identifier, hex2bin($masterPrivateKey), true);
        $childPrivateKey = hash('sha256', $childKeySeed);

        return '0x' . $childPrivateKey;
    }

    /**
     * Get child address from master key and identifier (without private key)
     * Useful for verifying an address or looking up which address belongs to an identifier
     *
     * @param string $masterPrivateKey Master private key
     * @param string $identifier The identifier (e.g., ORDER-12345)
     * @return string Child address
     * @throws Exception
     */
    public function getAddressForIdentifier(string $masterPrivateKey, string $identifier): string
    {
        $childPrivateKey = $this->getPrivateKeyForAddress($masterPrivateKey, $identifier);
        $childPrivateKey = $this->normalizePrivateKey($childPrivateKey);

        return $this->privateKeyToAddress($childPrivateKey);
    }

    /**
     * Spend/transfer funds from a child address using identifier
     * Uses master key + identifier to regenerate child key and sign transaction
     *
     * @param string $masterPrivateKey Master private key
     * @param string $identifier The identifier used when generating address
     * @param string $toAddress Recipient address
     * @param string $amount Amount to send (in ether/token units)
     * @param string|null $tokenAddress ERC20 token address (null for native token)
     * @return array Transaction details
     * @throws Exception
     */
    public function spendFromChildAddress(
        string $masterPrivateKey,
        string $identifier,
        string $toAddress,
        string $amount,
        ?string $tokenAddress = null
    ): array {
        // Regenerate child private key using identifier
        $childPrivateKey = $this->getPrivateKeyForAddress($masterPrivateKey, $identifier);
        $childPrivateKey = $this->normalizePrivateKey($childPrivateKey);

        $fromAddress = $this->privateKeyToAddress($childPrivateKey);

        if (!$this->isValidAddress($toAddress)) {
            throw new Exception('Invalid recipient address');
        }

        if ($tokenAddress === null) {
            // Send native token (ETH/GLMR/DEV)
            $amountWei = Utils::toWei($amount, 'ether');

            $tx = [
                'from' => $fromAddress,
                'to' => $toAddress,
                'value' => '0x' . $amountWei->toHex(),
                'gas' => '0x' . dechex(21000),
                'gasPrice' => '0x' . dechex($this->getGasPrice()),
                'nonce' => '0x' . dechex($this->getNonce($fromAddress)),
                'chainId' => $this->chainId
            ];

            $txHash = $this->signAndSendTransaction($tx, $childPrivateKey);

            return [
                'success' => true,
                'txHash' => $txHash,
                'from' => $fromAddress,
                'to' => $toAddress,
                'amount' => $amount,
                'token' => self::NETWORKS[$this->network][$this->chain]['symbol'],
                'identifier' => $identifier
            ];
        } else {
            // ERC20 transfer path
            if (!$this->isValidAddress($tokenAddress)) {
                throw new Exception('Invalid token contract address');
            }
            $txHash = $this->transferERC20($childPrivateKey, $tokenAddress, $toAddress, $amount);
            return [
                'success' => true,
                'txHash' => $txHash,
                'from' => $fromAddress,
                'to' => $toAddress,
                'amount' => $amount,
                'tokenAddress' => $tokenAddress,
                'identifier' => $identifier
            ];
        }
    }

    /**
     * Sweep funds from a child (by identifier) to destination. Sweeps native and optionally tokens.
     * On testnet, sweeps aUSDC; on mainnet, sweeps USDC and USDT if present.
     */
    public function sweepFromIdentifier(
        string $masterPrivateKey,
        string $identifier,
        string $destination,
        bool $sweepNative = true,
        ?array $tokenSymbols = null
    ): array {
        $childPriv = $this->normalizePrivateKey($this->getPrivateKeyForAddress($masterPrivateKey, $identifier));
        $results = ['native' => null, 'tokens' => []];

        if ($sweepNative) {
            $results['native'] = $this->sweepNative($childPriv, $destination);
        }

        $cfg = $this->getTokenConfig();
        $symbols = $tokenSymbols ?? ( $this->chainId === 1287 ? ['USDC'] : ['USDC','USDT'] );
        foreach ($symbols as $sym) {
            $upper = strtoupper($sym);
            if (!isset($cfg[$upper]) || !$cfg[$upper]['address']) continue;
            try {
                $results['tokens'][$upper] = $this->sweepERC20($childPriv, $upper, $destination);
            } catch (\Throwable $e) {
                $results['tokens'][$upper] = ['success' => false, 'reason' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Monitor a payment address for incoming transactions
     * Checks blockchain for payments and returns formatted results
     *
     * @param string $address The address to monitor
    * @param string $chain Must be 'evm'
     * @param string|null $tokenAddress ERC20 token address (for EVM chains only, null for native token)
     * @param float|null $usdRate USD exchange rate for the token/coin (optional)
     * @param string|null $lastCheckedBlock Block number/hash of last check (for pagination)
     * @return array Payment status with amount, token, and fiat value
     * @throws Exception
     */
    public function monitorPaymentAddress(
        string $address,
        string $chain,
        ?string $tokenAddress = null,
        ?float $usdRate = null,
        ?string $lastCheckedBlock = null
    ): array {
        if (strtolower($chain) !== 'evm') {
            throw new Exception('Only EVM chain is supported');
        }
        return $this->monitorEVMPayment($address, $tokenAddress, $usdRate, $lastCheckedBlock);
    }

    /**
     * Monitor EVM chain payment (native token or ERC20)
     */
    private function monitorEVMPayment(string $address, ?string $tokenAddress, ?float $usdRate, ?string $lastCheckedBlock): array
    {
        if (!$this->isValidAddress($address)) {
            throw new Exception('Invalid EVM address format');
        }

        $result = [
            'address' => $address,
            'chain' => 'evm',
            'network' => $this->network,
            'hasPayment' => false,
            'payments' => [],
            'totalAmount' => '0',
            'totalAmountFormatted' => '0',
            'fiatValue' => null,
            'token' => null,
            'decimals' => 18,
            'checkedAt' => date('Y-m-d H:i:s')
        ];

        try {
            if ($tokenAddress === null) {
                // Check native token balance (DEV/GLMR/ETH)
                $balance = $this->getBalance($address);
                $result['token'] = self::NETWORKS[$this->network][$this->chain]['symbol'];
                $result['decimals'] = self::NETWORKS[$this->network][$this->chain]['decimals'];
                $result['totalAmount'] = $balance;
                $result['totalAmountFormatted'] = number_format((float)$balance, 6, '.', '');

                if ((float)$balance > 0) {
                    $result['hasPayment'] = true;
                    $result['payments'][] = [
                        'type' => 'native',
                        'amount' => $balance,
                        'amountFormatted' => $result['totalAmountFormatted']
                    ];

                    if ($usdRate !== null) {
                        $result['fiatValue'] = [
                            'usd' => number_format((float)$balance * $usdRate, 2, '.', ''),
                            'rate' => $usdRate
                        ];
                    }
                }
            } else {
                // Check ERC20 token balance
                if (!$this->isValidAddress($tokenAddress)) {
                    throw new Exception('Invalid token contract address');
                }

                $contract = new Contract($this->web3->provider, '[{"constant":true,"inputs":[{"name":"account","type":"address"}],"name":"balanceOf","outputs":[{"name":"","type":"uint256"}],"type":"function"},{"constant":true,"inputs":[],"name":"decimals","outputs":[{"name":"","type":"uint8"}],"type":"function"},{"constant":true,"inputs":[],"name":"symbol","outputs":[{"name":"","type":"string"}],"type":"function"}]');
                $contract->at($tokenAddress);

                // Get token balance
                $balance = null;
                $contract->call('balanceOf', $address, function ($err, $res) use (&$balance) {
                    if ($err === null && isset($res[0])) {
                        $balance = $res[0]->toString();
                    }
                });

                // Get token decimals
                $decimals = 18;
                $contract->call('decimals', function ($err, $res) use (&$decimals) {
                    if ($err === null && isset($res[0])) {
                        $decimals = (int)$res[0]->toString();
                    }
                });

                // Get token symbol
                $symbol = 'TOKEN';
                $contract->call('symbol', function ($err, $res) use (&$symbol) {
                    if ($err === null && isset($res[0])) {
                        $symbol = $res[0];
                    }
                });

                if ($balance !== null) {
                    $formatted = bcdiv($balance, bcpow('10', (string)$decimals, 0), $decimals);

                    $result['token'] = $symbol;
                    $result['tokenAddress'] = $tokenAddress;
                    $result['decimals'] = $decimals;
                    $result['totalAmount'] = $balance;
                    $result['totalAmountFormatted'] = rtrim(rtrim($formatted, '0'), '.');

                    if (bccomp($balance, '0') > 0) {
                        $result['hasPayment'] = true;
                        $result['payments'][] = [
                            'type' => 'erc20',
                            'amount' => $balance,
                            'amountFormatted' => $result['totalAmountFormatted'],
                            'token' => $symbol,
                            'tokenAddress' => $tokenAddress
                        ];

                        if ($usdRate !== null) {
                            $fiatAmount = bcmul($formatted, (string)$usdRate, 2);
                            $result['fiatValue'] = [
                                'usd' => $fiatAmount,
                                'rate' => $usdRate
                            ];
                        }
                    }
                }
            }

            $result['currentBlock'] = $this->getBlockNumber();

        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Monitor balances across multiple EVM networks for USDC/USDT and native token.
     * @param string $address EVM address to monitor
     * @param array|null $networks Keys from NETWORKS to include (default: ['mainnet','eth-mainnet','bsc-mainnet'])
     * @param array $tokens Token symbols to include (default: ['USDC','USDT'])
     * @return array Map of networkKey => { native, tokens }
     */
    public function monitorBalancesAcrossChains(string $address, ?array $networks = null, array $tokens = ['USDC','USDT']): array
    {
        if (!$this->isValidAddress($address)) {
            throw new Exception('Invalid EVM address format');
        }
        // networks should be [['env'=>'mainnet','chain'=>'moonbeam'], ...]; default mainnets
        $nets = $networks ?? [
            ['env' => 'mainnet', 'chain' => 'moonbeam'],
            ['env' => 'mainnet', 'chain' => 'ethereum'],
            ['env' => 'mainnet', 'chain' => 'bsc'],
        ];
        $out = [];
        foreach ($nets as $entry) {
            if (!is_array($entry) || !isset($entry['env'], $entry['chain'])) {
                $out[] = ['error' => 'Invalid network entry format'];
                continue;
            }
            $env = $entry['env']; $chain = $entry['chain'];
            if (!isset(self::NETWORKS[$env][$chain])) { $out["$env:$chain"] = ['error' => 'Unknown network']; continue; }
            try {
                $cfg = self::NETWORKS[$env][$chain];
                $w3 = $this->makeWeb3ForRpc($cfg['rpc']);

                // Native balance
                $nativeBal = $this->getNativeBalanceOnWeb3($w3, $address);

                // Tokens
                $tkCfg = $this->getTokenConfig($env . ':' . $chain);
                $tkns = [];
                foreach ($tokens as $sym) {
                    $u = strtoupper($sym);
                    $addr = $tkCfg[$u]['address'] ?? null;
                    if (!$addr) { $tkns[$u] = ['available' => false]; continue; }
                    $bal = $this->getERC20BalanceOnWeb3($w3, $addr, $address);
                    $tkns[$u] = ['available' => true] + $bal + ['tokenAddress' => $addr];
                }

                $out["$env:$chain"] = [
                    'network' => $cfg['name'],
                    'chainId' => $cfg['chainId'],
                    'native' => [
                        'symbol' => $cfg['symbol'],
                        'decimals' => $cfg['decimals'],
                        'balance' => $nativeBal,
                    ],
                    'tokens' => $tkns,
                ];
            } catch (\Throwable $e) {
                $out["$env:$chain"] = ['error' => $e->getMessage()];
            }
        }
        return $out;
    }

    private function makeWeb3ForRpc(string $rpc, float $timeout = 20.0): \Web3\Web3
    {
        $rm = new HttpRequestManager($rpc, $timeout);
        return new Web3(new HttpProvider($rm));
    }

    private function getNativeBalanceOnWeb3(Web3 $w3, string $address): string
    {
        $balance = null; $error = null;
        $w3->eth->getBalance($address, function($err, $res) use (&$balance, &$error) {
            if ($err !== null) { $error = $err; return; }
            $balance = $res;
        });
        if ($error !== null) { throw new Exception('Failed to get native balance'); }
        return Utils::fromWei($balance, 'ether')->toString();
    }

    private function getERC20BalanceOnWeb3(Web3 $w3, string $tokenAddress, string $holder): array
    {
        $contract = new Contract($w3->provider, '[{"constant":true,"inputs":[{"name":"account","type":"address"}],"name":"balanceOf","outputs":[{"name":"","type":"uint256"}],"type":"function"},{"constant":true,"inputs":[],"name":"decimals","outputs":[{"name":"","type":"uint8"}],"type":"function"},{"constant":true,"inputs":[],"name":"symbol","outputs":[{"name":"","type":"string"}],"type":"function"}]');
        $contract->at($tokenAddress);

        $raw = '0'; $decimals = 18; $symbol = 'TOKEN';
        $contract->call('balanceOf', $holder, function ($err, $res) use (&$raw) { if ($err === null && isset($res[0])) { $raw = $res[0]->toString(); } });
        $contract->call('decimals', function ($err, $res) use (&$decimals) { if ($err === null && isset($res[0])) { $decimals = (int)$res[0]->toString(); } });
        $contract->call('symbol', function ($err, $res) use (&$symbol) { if ($err === null && isset($res[0])) { $symbol = (string)$res[0]; } });

        $formatted = bcdiv($raw, bcpow('10', (string)$decimals, 0), $decimals);
        return [
            'raw' => $raw,
            'formatted' => rtrim(rtrim($formatted, '0'), '.'),
            'decimals' => $decimals,
            'symbol' => $symbol,
        ];
    }

}