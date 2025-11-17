<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: POST');

use Slim\Csrf\Guard;
use Slim\Psr7\Factory\ResponseFactory;
use Manomite\{
    Exception\ManomiteException as ex,
    Route\Route,
    Engine\DateHelper,
    Engine\Security\PostFilter,
    Engine\Security\Encryption as Secret,
    Utility\FileGrabber,
    Utility\Process,
    Engine\Network,
    Engine\ArrayAdapter,
    Engine\CacheAdapter,
    Engine\Money\Helper as Money,
    Utility\Notification,
    Engine\Helper,
    Model\Reflect,
    Controller\SimpleRoute,
    Controller\Auth,
    Engine\Fingerprint,
    Controller\Views,
    Zorah\MoonbeamWalletManager,
    Zorah\AccountNumberGenerator,
    Zorah\ContractManager,
    Zorah\EscrowManager
};

include_once __DIR__ . '/../../../autoload.php';
$auth_handler = new Auth();
$auth         = $auth_handler->loggedin();
$errorLog     = 'zorah';

try {
    if (isset($auth['status']) && $auth['status'] !== false) {
        $security     = new PostFilter();
        $route        = new Route();
        $money        = new Money();
        $datehelper   = new DateHelper();
        $arrayAdapter = new ArrayAdapter();
        $fileGrabber  = new FileGrabber();
        $process      = new Process();
        $helper       = new Helper();

        if ($security->strip($_SERVER['REQUEST_METHOD']) === 'POST') {
            if (isset($_POST)) {
                $request     = $security->inputPost('request');
                $fingerprint = $security->inputPost('fingerprint');
                if ($security->nothing($request)) {
                    exit(json_encode(['status' => 400, 'error' => (new ex($errorLog, 3, 'Invalid request.'))->return()], JSON_PRETTY_PRINT));
                } elseif ($security->nothing($fingerprint)) {
                    exit(json_encode(['status' => 400, 'error' => (new ex($errorLog, 3, 'Failed security checks'))->return()], JSON_PRETTY_PRINT));
                } elseif ($auth_handler->is_block($fingerprint) !== false) {
                    exit(json_encode(['status' => 400, 'error' => (new ex($errorLog, 3, 'Failed security checks'))->return()], JSON_PRETTY_PRINT));
                }

                $cache    = new CacheAdapter();
                $userModel = new Reflect('User');
                $userData  = $userModel->getUserByAuthToken($auth['user']);
                if ($userData === null && $userData['status'] !== 1) {
                    exit(json_encode(['status' => 400, 'error' => (new ex($errorLog, 3, 'User not found or account has been suspended.'))->return()], JSON_PRETTY_PRINT));
                }

                ///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
                $simpleRoute = new SimpleRoute();
                //All entire routes are placed here
                $allowedRoutes = [
                    '/logout',
                    '/loadAccounts',
                    '/getAccountBundle',
                    '/dashboard',
                    '/getSupportedCurrency',
                    '/getExchangeRate',
                    '/createAccount',
                    '/openAccountModal',
                    '/receiveFund',
                    '/generateP2PTransfer',
                    '/p2pTransferExpired',
                    '/cancelP2PTransfer',
                    '/confirmP2PPayment'

                ];
                $simpleRoute->registeredRoute = $allowedRoutes;
                $request                      = '/' . $request;

                $responseFactory = new ResponseFactory();
                $guard           = new Guard($responseFactory);

                ///////////////////////////////////////////////////////////////////////////////////////////////////////////////////
                $simpleRoute->route('/logout', function () {
                    if (session_id() !== '') {
                        session_start();   // Start session if not already started
                        session_unset();   // Unset all session variables
                        session_destroy(); // Destroy the session
                    }
                    exit(json_encode(['status' => 200], JSON_PRETTY_PRINT));
                });

                $simpleRoute->route('/loadAccounts', function () use ($userData, $security, $route, $datehelper, $arrayAdapter, $money, $errorLog) {
                    try {

                        $accountModel = new Reflect('Account');
                        $allAccounts = '';
                        $accounts = $accountModel->getAccountsByAuthToken($userData['authToken']);

                        $moonbeam = new MoonbeamWalletManager(CONFIG->get('zorah_environment'), 'moonbeam');

                        if (is_array($accounts) && !$arrayAdapter->isEmpty($accounts)) {
                            $template = file_get_contents(__DIR__ . '/../../../' . BODY_TEMPLATE_FOLDER . '/home/inline/accounts.html');
                            foreach ($accounts as $account) {
                                 //Check smart contract to update balance
                                $wallet = json_decode($account['wallet'], true);
                                $balance = $moonbeam->getERC20Balance('0xD1633F7Fb3d716643125d6415d4177bC36b7186b', $wallet['address']);

                                if(isset($balance['formatted']) && !empty($balance['formatted'])){
                                    $accountModel->updateAccount(['account_id' => $account['account_id']], ['wallet.closing_balance' => $balance['formatted']]);
                                }

                                $allAccounts .= $route->textRender(
                                    $template,
                                    array(
                                    'account_id' => $account['account_id'],
                                    'account_title' => $account['account_title'],
                                    'account_balance' => isset($wallet['closing_balance']) ? $money->format($wallet['closing_balance'], 'USD') : $money->format(0, 'USD')
                                    )
                                );
                            }
                        } else {
                            $allAccounts = '<p class="text-sm text-gray-500">No accounts found.</p>';
                        }

                        exit(json_encode(['status' => 200, 'accounts' => $allAccounts], JSON_PRETTY_PRINT));

                    } catch (\Exception $e) {
                        (new ex($errorLog . '_loadAccounts', 5, $e))->return();
                        exit(json_encode(['status' => 400, 'error' => LANG->get('TECHNICAL_ERROR')], JSON_PRETTY_PRINT));
                    }
                });

                $simpleRoute->route('/getAccountBundle', function () use ($userData, $security, $route, $datehelper, $arrayAdapter, $money, $errorLog) {
                    try {

                        $account_id = $security->inputPost('account_id');
                        if($security->nothing($account_id)){
                            exit(json_encode(['status' => 400, 'error' => (new ex($errorLog.'_getAccountBundle', 3, 'Invalid account'))->return()], JSON_PRETTY_PRINT));
                        }

                        $accountModel = new Reflect('Account');
                        $account = $accountModel->getAccountsByAccountId($userData['authToken'], $account_id);

                        if (is_array($account) && !$arrayAdapter->isEmpty($account)) {
                            $template = file_get_contents(__DIR__ . '/../../../' . BODY_TEMPLATE_FOLDER . '/home/inline/dashboard.html');

                            $wallet = json_decode($account['wallet'], true);
                                
                            $interface = $route->textRender(
                                $template,
                                array(
                                    'account_name' => $account['account_title'] .' Account',
                                    'balance' => $money->format($wallet['closing_balance'], 'USD'),
                                    'account_id' => $account['account_id'],
                                )
                            );

                             exit(json_encode(['status' => 200, 'interface' => $interface], JSON_PRETTY_PRINT));

                        } else {
                            exit(json_encode(['status' => 400, 'error' => (new ex($errorLog.'_getAccountBundle', 3, 'Invalid account'))->return()], JSON_PRETTY_PRINT));
                        }

                    } catch (\Exception $e) {
                        (new ex($errorLog . '_getAccountBundle', 5, $e))->return();
                        exit(json_encode(['status' => 400, 'error' => LANG->get('TECHNICAL_ERROR')], JSON_PRETTY_PRINT));
                    }
                });


                $simpleRoute->route('/dashboard', function () use ($userData, $security, $route, $datehelper, $arrayAdapter, $money, $errorLog) {
                    try {

                        $accountModel = new Reflect('Account');
                        $allAccounts = '';
                        $account = $accountModel->getAccountsByAuthTokenAndTitle($userData['authToken'], 'Default');

                        if (is_array($account) && !$arrayAdapter->isEmpty($account)) {
                            $template = file_get_contents(__DIR__ . '/../../../' . BODY_TEMPLATE_FOLDER . '/home/inline/dashboard.html');

                            $wallet = json_decode($account['wallet'], true);
                            
                            $interface = $route->textRender(
                                $template,
                                array(
                                    'account_name' => $account['account_title'] .' Account',
                                    'balance' => $money->format($wallet['closing_balance'], 'USD'),
                                    'root' => APP_DOMAIN.'/home/',
                                    'app_name' => APP_NAME,
                                    'account_id' => $account['account_id'],
                                )
                            );

                             exit(json_encode(['status' => 200, 'interface' => $interface], JSON_PRETTY_PRINT));
                            
                        } else {
                            //Create a default account if none exists
                            $template = file_get_contents(__DIR__ . '/../../../' . BODY_TEMPLATE_FOLDER . '/home/modal/account.html');

                            $modal = $route->textRender(
                                $template,
                                array(
                                    'apy' => CONFIG->get('savings_apy')
                                )
                            );

                             exit(json_encode(['status' => 200, 'modal' => $modal, 'modalId' => 'newAccountModal'], JSON_PRETTY_PRINT));
                        }
                    } catch (\Exception $e) {
                        (new ex($errorLog . '_dashboard', 5, $e))->return();
                        exit(json_encode(['status' => 400, 'error' => LANG->get('TECHNICAL_ERROR')], JSON_PRETTY_PRINT));
                    }
                });

                $simpleRoute->route('/getSupportedCurrency', function () use ($errorLog) {
                    try {

                        $currencyList = json_decode(file_get_contents(SYSTEM_DIR . '/files/countries/country-by-currency-code.json'), true);

                        $supportedCurrencies = [];
                        $seenCodes = [];
                        
                        foreach($currencyList as $currency){
                            $code = $currency['currency_code'];
                            // Only add if we haven't seen this code before
                            if (!in_array($code, $seenCodes)) {
                                $seenCodes[] = $code;
                                $supportedCurrencies[] = $code;
                            }
                        }

                        exit(json_encode(['status' => 200, 'supportedCurrencies' => $supportedCurrencies], JSON_PRETTY_PRINT));

                    } catch (\Exception $e) {
                        (new ex($errorLog . '_getSupportedCurrency', 5, $e))->return();
                        exit(json_encode(['status' => 400, 'error' => LANG->get('TECHNICAL_ERROR')], JSON_PRETTY_PRINT));
                    }
                });

                $simpleRoute->route('/getExchangeRate', function () use ($errorLog, $money, $security) {
                    try {

                        $currency = $security->inputPost('currency');
                        if($security->nothing($currency)){
                            exit(json_encode(['status' => 400, 'error' => (new ex($errorLog.'_getExchangeRate', 3, 'Currency code is required.'))->return()], JSON_PRETTY_PRINT));
                        }

                        $currencyList = json_decode(file_get_contents(SYSTEM_DIR . '/files/countries/country-by-currency-code.json'), true);

                        $source = '';
                        foreach($currencyList as $list){
                           if($list['currency_code'] === $currency){
                               $source = $list['currency_code'];
                               break;
                           }
                        }

                        if($source === ''){
                            exit(json_encode(['status' => 400, 'error' => (new ex($errorLog.'_getExchangeRate', 3, 'Unsupported currency code.'))->return()], JSON_PRETTY_PRINT));
                        }

                        if($source === 'USD'){
                            exit(json_encode(['status' => 200, 'rate' => 1], JSON_PRETTY_PRINT));
                        }

                        $rate = $money->exchange($source);
                        
                        exit(json_encode(['status' => 200, 'rate' => $rate], JSON_PRETTY_PRINT));

                    } catch (\Exception $e) {
                        (new ex($errorLog.'_getExchangeRate', 5, $e))->return();
                        exit(json_encode(['status' => 400, 'error' => LANG->get('TECHNICAL_ERROR')], JSON_PRETTY_PRINT));
                    }
                });

                $simpleRoute->route('/createAccount', function () use ($userData, $security, $errorLog, $arrayAdapter, $datehelper, $money, $route) {
                    try {
                        // Get and validate input
                        $accountType = strtolower(trim($security->inputPost('account_type')));
                        $currency = $security->inputPost('currency');
                        $initialDeposit = floatval($security->inputPost('initial_deposit'));
                        $accountPassword = $security->inputPost('account_password');
                        $accountTitle = $security->inputPost('account_title');

                        // Validate account type
                        if (!in_array($accountType, ['savings', 'current'])) {
                            exit(json_encode(['status' => 400, 'error' => 'Invalid account type. Must be savings or current.'], JSON_PRETTY_PRINT));
                        }

                        // Validate currency
                        if ($security->nothing($currency) || strlen($currency) !== 3) {
                            exit(json_encode(['status' => 400, 'error' => 'Invalid currency code.'], JSON_PRETTY_PRINT));
                        }

                        $currencyList = json_decode(file_get_contents(SYSTEM_DIR . '/files/countries/country-by-currency-code.json'), true);

                        $source = '';
                        foreach($currencyList as $list){
                           if($list['currency_code'] === $currency){
                               $source = $list['currency_code'];
                               break;
                           }
                        }

                        if($source === ''){
                            exit(json_encode(['status' => 400, 'error' => 'Unsupported currency code.'], JSON_PRETTY_PRINT));
                        }

                        // Validate initial deposit for current account
                        if ($accountType === 'current' && $initialDeposit < 5) {
                            exit(json_encode(['status' => 400, 'error' => 'Minimum initial deposit for Current account is $5.00'], JSON_PRETTY_PRINT));
                        }

                        // Validate account password
                        if ($security->nothing($accountPassword)) {
                            exit(json_encode(['status' => 400, 'error' => 'Account password is required.'], JSON_PRETTY_PRINT));
                        }

                        // Check password strength (at least 8 chars, uppercase, lowercase, number, special char)
                        if (strlen($accountPassword) < 8 || 
                            !preg_match('/[A-Z]/', $accountPassword) ||
                            !preg_match('/[a-z]/', $accountPassword) ||
                            !preg_match('/[0-9]/', $accountPassword) ||
                            !preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $accountPassword)) {
                            exit(json_encode(['status' => 400, 'error' => 'Account password does not meet security requirements.'], JSON_PRETTY_PRINT));
                        }

                        if(empty($accountTitle)){
                           $accountTitle = 'Default';
                        }

                        if ((new Secret())::verify_hash($userData['pass'], $accountPassword)) {
                            exit(json_encode(['status' => 400, 'error' => 'Account password must be different from your login password.'], JSON_PRETTY_PRINT));
                        }

                        // Create wallet with encrypted private key
                        try {
                            $walletManager = new MoonbeamWalletManager(CONFIG->get('zorah_environment'), 'moonbeam');
                            $wallet = $walletManager->createWalletWithPassword($accountPassword);
                            
                            // Generate bank account number from wallet private key
                            // Decrypt the private key temporarily to generate account number
                            $privateKey = $walletManager->decryptPrivateKey($wallet['encryptedPrivateKey'], $accountPassword);
                            
                            $accountModel = new Reflect('Account');
                            $genResult = AccountNumberGenerator::generateUniqueFromPrivateKeyWithNonce(
                                $privateKey,
                                function($accNum) use ($accountModel) {
                                    // Check if account number already exists in database
                                    $exists = $accountModel->getAccountsByAccountNumber($accNum);
                                    return !empty($exists);
                                },
                                11  // 11-digit bank account number
                            );

                            $accountNumber = $genResult['accountNumber'];
                            $accountNonce = isset($genResult['nonce']) ? intval($genResult['nonce']) : 0;
                            $accountId = (new Secret())->uuid();

                            // Clear private key from memory
                            $privateKey = null;
                            unset($privateKey);
                            
                        } catch (\Exception $e) {
                            (new ex($errorLog . '_createAccount', 5, $e))->return();
                            exit(json_encode(['status' => 400, 'error' => LANG->get('TECHNICAL_ERROR')], JSON_PRETTY_PRINT));
                        }

                        $apy = ($accountType === 'savings') ? CONFIG->get('savings_apy') : 0;

                        $accountData = [
                            'authToken' => $userData['authToken'],
                            'account_id' => $accountId,
                            'account_name' => $userData['name'],
                            'account_number' => $accountNumber,
                            'account_number_nonce' => $accountNonce,
                            'account_title' => $accountTitle,
                            'account_type' => $accountType,
                            'initial_deposit_currency' => $currency,
                            'minimum_deposit' => $initialDeposit,
                            'apy' => $apy,
                            'wallet_address' => $wallet['address'],
                            'wallet_public_key' => $wallet['publicKey'],
                            'encrypted_private_key' => $wallet['encryptedPrivateKey'],
                            'status' => 1, // Active
                            'created_at' => $datehelper->timestampTimeNow(),
                            'updated_at' => $datehelper->timestampTimeNow()
                        ];

                        // Insert account into database
                        $result = $accountModel->createAccount($accountData);

                        if ($result) {

                            $subject = 'Welcome to Your New ' . ucfirst($accountTitle) . ' Account!';

                            $message = $route->textRender(file_get_contents(__DIR__ . '/../../../' . BODY_TEMPLATE_FOLDER . '/email/account.html'), array(
                                'logo' => APP_DOMAIN.'/asset/script/images/logo/logo.png',
                                'lang_service_request' => 'Account Successfully Created',
                                'account_name' => $userData['name'],
                                'account_title' => $accountTitle,
                                'account_number' => $accountNumber,
                                'account_type' => ucfirst($accountType),
                                'currency' => 'multiple',
                                'initial_deposit' => number_format($initialDeposit, 2),
                                'created_at' => $datehelper->formatDate($accountData['created_at']),
                                'support_mail' => SUPPORT_EMAIL,
                                'footer' => COPYRIGHTS,
                            ));

                            (new Notification())->sendToEmail($subject, $userData['email'], $message);

                            exit(json_encode(['status' => 200, 'success' => true, 'message' => 'Your ' . $accountTitle . ' account has been successfully created!', 'account_name' => $userData['name'], 'account_number' => $accountNumber
                            ], JSON_PRETTY_PRINT));
                        } else {
                            exit(json_encode(['status' => 400, 'error' => 'Failed to create account. Please try again.'], JSON_PRETTY_PRINT));
                        }

                    } catch (\Exception $e) {
                        (new ex($errorLog . '_createAccount', 5, $e))->return();
                        exit(json_encode(['status' => 400, 'error' => LANG->get('TECHNICAL_ERROR')], JSON_PRETTY_PRINT));
                    }
                });

                $simpleRoute->route('/openAccountModal', function () use ($userData, $security, $route, $datehelper, $arrayAdapter, $money, $errorLog) {
                    try {

                        $accountModel = new Reflect('Account');
                        $totalAccounts = $accountModel->countAccountByAuthToken($userData['authToken']);

                        if($totalAccounts >= CONFIG->get('max_accounts_per_user')){
                            exit(json_encode(['status' => 400, 'error' => 'You have reached the maximum number of accounts allowed.'], JSON_PRETTY_PRINT));
                        }
                       
                        $template = file_get_contents(__DIR__ . '/../../../' . BODY_TEMPLATE_FOLDER . '/home/modal/account_self.html');

                        $modal = $route->textRender(
                            $template,
                            array(
                                'apy' => CONFIG->get('savings_apy')
                            )
                        );

                        exit(json_encode(['status' => 200, 'modal' => $modal, 'modalId' => 'newAccountModal'], JSON_PRETTY_PRINT));
                        
                    } catch (\Exception $e) {
                        (new ex($errorLog . '_openAccountModal', 5, $e))->return();
                        exit(json_encode(['status' => 400, 'error' => LANG->get('TECHNICAL_ERROR')], JSON_PRETTY_PRINT));
                    }
                });

                $simpleRoute->route('/receiveFund', function () use ($userData, $security, $route, $datehelper, $arrayAdapter, $money, $errorLog) {
                    try {

                        $account_id = $security->inputPost('account_id');
                        if($security->nothing($account_id)){
                            exit(json_encode(['status' => 400, 'error' => (new ex($errorLog.'_getAccountBundle', 3, 'Invalid account'))->return()], JSON_PRETTY_PRINT));
                        }

                        $accountModel = new Reflect('Account');
                        $account = $accountModel->getAccountsByAccountId($userData['authToken'], $account_id);

                        if (is_array($account) && !$arrayAdapter->isEmpty($account)) {

                            $template = file_get_contents(__DIR__ . '/../../../' . BODY_TEMPLATE_FOLDER . '/home/modal/receive.html');

                            $wallet = json_decode($account['wallet'], true);

                            $modal = $route->textRender(
                                $template,
                                array(
                                    'app_name' => APP_NAME,
                                    'account_id' => $account['account_id'],
                                    'account_name' => (isset($userData['is_business']) && $userData['is_business'] === true) ? $userData['kyc']['business_name'] : $userData['name'],
                                    'account_number' => $account['account_number'],
                                    'account_type' => ucfirst($account['account_type']),
                                    'status' => ($account['status'] == 1) ? 'Active' : 'Inactive',
                                    'status_color' => ($account['status'] == 1) ? 'green' : 'red',
                                    'csrf' => (new Views)->csrf_inject(),
                                    'wallet_address' => $wallet['address']
                                )
                            );
                            exit(json_encode(['status' => 200, 'modal' => $modal, 'modalId' => 'receiveFundModal'], JSON_PRETTY_PRINT));
                        }
                        exit(json_encode(['status' => 400, 'error' => 'Invalid account'], JSON_PRETTY_PRINT));
                        
                    } catch (\Exception $e) {
                        (new ex($errorLog . '_openAccountModal', 5, $e))->return();
                        exit(json_encode(['status' => 400, 'error' => LANG->get('TECHNICAL_ERROR')], JSON_PRETTY_PRINT));
                    }
                });

                $simpleRoute->route('/generateP2PTransfer', function () use ($userData, $security, $route, $datehelper, $arrayAdapter, $money, $errorLog) {
                    try {

                        $csrf_name = $security->inputPost('csrf_name');
                        $csrf_value = $security->inputPost('csrf_value');

                        if ($guard->validateToken($csrf_name, $csrf_value) === false) {
                            exit(json_encode(['status' => 400, 'error' => (new ex($errorLog . '_generateP2PTransfer', 6, LANG->get('EXTERNAL_REJECTED')))->return()], JSON_PRETTY_PRINT));
                        }

                        $account_id = $security->inputPost('account_id');
                        if($security->nothing($account_id)){
                            exit(json_encode(['status' => 400, 'error' => (new ex($errorLog.'_generateP2PTransfer', 3, 'Invalid account'))->return()], JSON_PRETTY_PRINT));
                        }
                        $currency = $security->inputPost('currency');
                        $amount = $security->inputPost('amount');

                        if($security->nothing($currency) || $security->nothing($amount)){
                            exit(json_encode(['status' => 400, 'error' => (new ex($errorLog.'_generateP2PTransfer', 3, 'Invalid params'))->return()], JSON_PRETTY_PRINT));
                        }

                        $accountModel = new Reflect('Account');
                        $account = $accountModel->getAccountsByAccountId($userData['authToken'], $account_id);

                        if (is_array($account) && !$arrayAdapter->isEmpty($account)) {

                            $currencyList = json_decode(file_get_contents(SYSTEM_DIR . '/files/countries/country-by-currency-code.json'), true);

                            $source = '';
                            foreach($currencyList as $list){
                                if($list['currency_code'] === $currency){
                                    $source = $list['currency_code'];
                                    break;
                                }
                            }

                            if($source === ''){
                                exit(json_encode(['status' => 400, 'error' => (new ex($errorLog.'_generateP2PTransfer', 3, 'Unsupported currency code.'))->return()], JSON_PRETTY_PRINT));
                            }

                            $liquidityModel = new Reflect('Liquidity');
                            $liquidity = $liquidityModel->getLiquidityByCurrencyAndAmount($currency, floatval($amount));

                            if (is_null($liquidity) || $arrayAdapter->isEmpty($liquidity)) {
                                exit(json_encode(['status' => 400, 'error' => (new ex($errorLog.'_generateP2PTransfer', 3, 'Sorry! we do not currently support your country. Please contact support if you want us to.'))->return()], JSON_PRETTY_PRINT));
                            }

                            $rate = $money->exchange($source);

                            if(empty($rate)){
                                exit(json_encode(['status' => 400, 'error' => (new ex($errorLog.'_generateP2PTransfer', 3, 'Failed to get exchange rate. Please try again'))->return()], JSON_PRETTY_PRINT));
                            }
                            $receiving = floatval($amount) / $rate;

                            // Bank details to use (default to liquidity provider's fixed account)

                            $bankName = $liquidity['bank_name'] ?? '';
                            $accountName = $liquidity['account_name'] ?? '';
                            $accountNumber = $liquidity['account_number'] ?? '';
                            $reference = null;

                            //Lets ask provider if they have an account to use instead of fixed account
                            if(isset($liquidity['webhook'])){
                                //It seems provider has webhook configured for this sale.
                                $response = (new Notification())->sendToWebhook('giveMeAccount', $liquidity['webhook']['secret_key'], $liquidity['webhook']['url'], [
                                    'currency' => $currency,
                                    'amount' => floatval($amount),
                                    'amount_usd' => $receiving
                                ], $liquidity['telegram_id']);

                                // Check if webhook returned valid bank details
                                if (is_array($response) && $response['status'] === true && isset($response['payload'])) {
                                    $payload = $response['payload'];
                                    
                                    // Validate and use webhook-provided bank details
                                    if (isset($payload['bank_name']) && !empty($payload['bank_name'])) {
                                        $bankName = $payload['bank_name'];
                                    }
                                    
                                    if (isset($payload['account_name']) && !empty($payload['account_name'])) {
                                        $accountName = $payload['account_name'];
                                    }
                                    
                                    if (isset($payload['account_number']) && !empty($payload['account_number'])) {
                                        $accountNumber = $payload['account_number'];
                                    }
                                    
                                    if (isset($payload['reference']) && !empty($payload['reference'])) {
                                        $reference = $payload['reference'];
                                    }
                                } else {
                                    // Log webhook failure but continue with default account
                                    (new ex($errorLog.'_generateP2PTransfer', 3, 'Webhook failed to provide bank details. Using default account. Response: '.json_encode($response)))->return();
                                }
                            }

                            //Call smart contract to generate P2P
                            $walletManager = new MoonbeamWalletManager(CONFIG->get('zorah_environment'), 'moonbeam');
                            $walletManager->setGasWallet(CONFIG->get('relayer_key'));

                            $contractManager = new ContractManager($walletManager, CONFIG->get('moonbeam_escrow_contract'), SYSTEM_DIR . '/files/contracts/moonbeam_main_contract_abi.json');

                            //Check provider contract vault balance
                            if($contractManager->getVaultBalance($liquidity['wallet_address']) < $receiving){
                                //Update provider here through notification to add more liquidity - future implementation

                                exit(json_encode(['status' => 400, 'error' => (new ex($errorLog.'_generateP2PTransfer', 3, 'Network error. Please try again.'))->return()], JSON_PRETTY_PRINT));
                            }
                            $wallet = json_decode($account['wallet'], true);
                            $escrowDurationInHours = CONFIG->get('escrow_expires') * 3600;
                            
                            $escrowResult = $contractManager->createEscrow(
                                $liquidity['wallet_address'],     // seller (liquidity provider)
                                $wallet['address'], // buyer (receiver)
                                number_format($receiving, 2, '.', ''),  // amount in USD
                                $escrowDurationInHours,           // duration in seconds
                                true                 //Its automated because fund is in automated vault           
                            );

                            if (!$escrowResult['success']) {
                                exit(json_encode(['status' => 400, 'error' => 'Failed to create escrow on blockchain. Please try again'], JSON_PRETTY_PRINT));
                            }

                            $expires = $datehelper->timestampTimeNow() + ( $escrowDurationInHours * 3600);

                            //Store data in db
                            $escrowDB = new Reflect('Escrow');
                            $payload = [
                                'escrow_id' => $escrowResult['escrowId'],
                                'liquidity_id' => $liquidity['authToken'],
                                'tx_hash' => $escrowResult['txHash'],
                                'gas_fee' => $escrowResult['gasUsed'],
                                'seller_wallet' => $liquidity['wallet_address'],
                                'buyer_wallet' => $wallet['address'],
                                'amount_usd' => number_format($receiving, 2, '.', ''),
                                'deposit_currency' => $currency,
                                'deposit_amount' => floatval($amount),
                                'bank_name' => $bankName,
                                'account_name' => $accountName,
                                'account_number' => $accountNumber,
                                'reference' => $reference,
                                'created_by' => $userData['authToken'],
                                'account_id' => $account['account_id'],
                                'status' => 'pending',
                                'created_at' => $datehelper->timestampTimeNow(),
                                'expires_at' => $expires,
                            ];

                            $result = $escrowDB->createEscrow($payload);
                            if ($result) {

                                $subject = 'Payment Request | Escrow ' . $escrowResult['escrowId'];

                                $message = $route->textRender(file_get_contents(__DIR__ . '/../../../' . BODY_TEMPLATE_FOLDER . '/email/escrow.html'), array(
                                    'logo' => APP_DOMAIN.'/asset/script/images/logo/logo.png',
                                    'lang_service_request' => 'Payment Request Created',
                                    'trans_id' => $escrowResult['escrowId'],
                                    'amount' => $money->format($amount, $currency),
                                    'bank_name' => $bankName,
                                    'account_name' => $accountName,
                                    'account_number' => $accountNumber,
                                    'reference' => $reference,
                                    'receiving_amount' => $money->format($receiving, 'USD'),
                                    'expiry_time' => $datehelper->formatDate($expires),
                                    'support_mail' => SUPPORT_EMAIL,
                                    'footer' => COPYRIGHTS,
                                ));

                                (new Notification())->sendToEmail($subject, $userData['email'], $message);

                                $payload['sender_name'] = $userData['name'];
                                $payload['amount_usd'] = $money->format($receiving, 'USD');
                                $payload['expires_at'] = $datehelper->formatDate($expires);
                                $payload['created_at'] = $datehelper->formatDate($payload['created_at']);
                                $payload['deposit_amount'] = $money->format($amount, $currency);
                                unset($payload['buyer_wallet']);
                                unset($payload['seller_wallet']);
                                unset($payload['created_by']);
                                unset($payload['account_id']);
                                unset($payload['liquidity_id']);

                                (new Notification())->sendToTelegram($liquidity['telegram_id'], $subject.' | Please press confirm button once you receive the payment or login to your account to press confirm.', $payload);

                                if(isset($liquidity['webhook'])){
                                    //Update webhook url if provider has webhook configured for this sale.
                                    $response = (new Notification())->sendToWebhook('escrowCreated', $liquidity['webhook']['secret_key'], $liquidity['webhook']['url'], $payload, $liquidity['telegram_id']);

                                    if(!is_array($response) || $response['status'] !== true){
                                        //Log webhook failure but do not block the process
                                        (new ex($errorLog.'_generateP2PTransfer', 3, 'Failed to send webhook to liquidity provider for escrow '.$escrowResult['escrowId'].'. Response: '.json_encode($response)))->return();
                                    }
                                }

                                $modal = file_get_contents(__DIR__ . '/../../../' . BODY_TEMPLATE_FOLDER . '/home/inline/receivep2p.html');

                                exit(json_encode([
                                    'status' => 200, 
                                    'modal' => $modal,
                                    'escrow_id' => $escrowResult['escrowId'],
                                    'txHash' => $escrowResult['txHash'],
                                    'amount' => $money->format($amount, $currency),
                                    'currency' => $currency,
                                    'bank_name' => $bankName,
                                    'account_name' => $accountName,
                                    'account_number' => $accountNumber,
                                    'reference' => $reference,
                                    'expiry_time' => $escrowDurationInHours * 3600,

                                ], JSON_PRETTY_PRINT));
                            } else {
                                exit(json_encode(['status' => 400, 'error' => 'Failed to create escrow. Please try again.'], JSON_PRETTY_PRINT));
                            }
                        }
                        
                        exit(json_encode(['status' => 400, 'error' => 'Invalid account'], JSON_PRETTY_PRINT));
                        
                    } catch (\Exception $e) {
                        (new ex($errorLog . '_generateP2PTransfer', 5, $e))->return();
                        exit(json_encode(['status' => 400, 'error' => LANG->get('TECHNICAL_ERROR')], JSON_PRETTY_PRINT));
                    }
                });

                $simpleRoute->route('/p2pTransferExpired', function () use ($userData, $security, $route, $datehelper, $arrayAdapter, $money, $errorLog) {
                    try {

                        $csrf_name = $security->inputPost('csrf_name');
                        $csrf_value = $security->inputPost('csrf_value');

                        if ($guard->validateToken($csrf_name, $csrf_value) === false) {
                            exit(json_encode(['status' => 400, 'error' => (new ex($errorLog . '_p2pTransferExpired', 6, LANG->get('EXTERNAL_REJECTED')))->return()], JSON_PRETTY_PRINT));
                        }

                        $account_id = $security->inputPost('account_id');
                        if($security->nothing($account_id)){
                            exit(json_encode(['status' => 400, 'error' => (new ex($errorLog.'_p2pTransferExpired', 3, 'Invalid account'))->return()], JSON_PRETTY_PRINT));
                        }
                        $escrowId = $security->inputPost('escrow_id');
                       
                        if($security->nothing($escrowId)){
                            exit(json_encode(['status' => 400, 'error' => (new ex($errorLog.'_p2pTransferExpired', 3, 'Invalid params'))->return()], JSON_PRETTY_PRINT));
                        }

                        $accountModel = new Reflect('Account');
                        $account = $accountModel->getAccountsByAccountId($userData['authToken'], $account_id);

                        if (is_array($account) && !$arrayAdapter->isEmpty($account)) {

                            $escrowDB = new Reflect('Escrow');
                            $escrowData = $escrowDB->getEscrowById($userData['authToken'],$escrowId);
                            
                            if (!is_array($escrowData) || $arrayAdapter->isEmpty($escrowData)) {
                                exit(json_encode(['status' => 400, 'error' => 'Invalid Payment'], JSON_PRETTY_PRINT));
                            } else if($escrowData['status'] === 'user_confirmed'){
                                exit(json_encode(['status' => 400, 'error' => 'User confirmed payment cannot be cancelled'], JSON_PRETTY_PRINT));
                            } else if($escrowData['status'] === 'dispute'){
                                exit(json_encode(['status' => 400, 'error' => 'Disputed payment cannot be cancelled'], JSON_PRETTY_PRINT));
                            } else if($escrowData['status'] === 'cancelled'){
                                 exit(json_encode(['status' => 400, 'error' => 'Cancelled payment cannot be re-cancelled'], JSON_PRETTY_PRINT));
                            }

                            //Call smart contract to generate P2P
                            $walletManager = new MoonbeamWalletManager(CONFIG->get('zorah_environment'), 'moonbeam');
                            $walletManager->setGasWallet(CONFIG->get('relayer_key'));

                            $contractManager = new ContractManager($walletManager, CONFIG->get('moonbeam_escrow_contract'), SYSTEM_DIR . '/files/contracts/moonbeam_main_contract_abi.json');

                            $result = $contractManager->cancelEscrow($escrowData['escrow_id']);

                            if(!$result['success']){
                                exit(json_encode(['status' => 400, 'error' => 'Failed to cancel payment on blockchain. Please try again or contact support'], JSON_PRETTY_PRINT));
                            }

                            $reasons = 'Escrow expired without payment confirmation from buyer.';

                            $escrowUpdate = $escrowDB->updateEscrowStatus($userData['authToken'], $escrowId, 'cancelled', $datehelper->timestampTimeNow(), $reasons);

                            if ($escrowUpdate) {

                                $subject = 'Payment Cancelled | Escrow ' . $escrowData['escrowId'];

                                $message = $route->textRender(file_get_contents(__DIR__ . '/../../../' . BODY_TEMPLATE_FOLDER . '/email/escrow_cancelled.html'), array(
                                    'logo' => APP_DOMAIN.'/asset/script/images/logo/logo.png',
                                    'lang_service_request' => 'Payment Request Created',
                                    'trans_id' => $escrowData['escrow_id'],
                                    'support_mail' => SUPPORT_EMAIL,
                                    'footer' => COPYRIGHTS,
                                ));

                                (new Notification())->sendToEmail($subject, $userData['email'], $message);

                                $payload = [
                                    'escrow_id' => $escrowData['escrow_id'],
                                    'tx_hash' => $result['txHash'],
                                    'gas_fee' => $result['gasUsed'],
                                    'amount_usd' => $money->format($escrowData['amount_usd'], 'USD'),
                                    'deposit_currency' => $escrowData['deposit_currency'],
                                    'deposit_amount' =>  $money->format($escrowData['deposit_amount'], $escrowData['deposit_currency']),
                                    'status' => 'cancelled',
                                    'created_at' => $datehelper->formatDate($escrowData['created_at']),
                                    'expires_at' => $datehelper->formatDate($escrowData['expires_at']),
                                ];

                                $liquidityModel = new Reflect('Liquidity');
                                $liquidity = $liquidityModel->getLiquidityById($escrowData['liquidity_id']);

                                (new Notification())->sendToTelegram($liquidity['telegram_id'], $subject, $payload);

                                if(isset($liquidity['webhook'])){
                                    //Update webhook url if provider has webhook configured for this sale.
                                    $response = (new Notification())->sendToWebhook('escrowExpired', $liquidity['webhook']['secret_key'], $liquidity['webhook']['url'], $payload, $liquidity['telegram_id']);

                                    if(!is_array($response) || $response['status'] !== true){
                                        //Log webhook failure but do not block the process
                                        (new ex($errorLog.'_p2pTransferExpired', 3, 'Failed to send webhook to liquidity provider for escrow '.$escrowData['escrow_id'].'. Response: '.json_encode($response)))->return();
                                    }
                                }

                                exit(json_encode([
                                    'status' => 200, 
                                    'success' => true,
                                ], JSON_PRETTY_PRINT));
                            } else {
                                exit(json_encode(['status' => 400, 'error' => 'Failed to create escrow. Please try again.'], JSON_PRETTY_PRINT));
                            }
                        }
                        
                        exit(json_encode(['status' => 400, 'error' => 'Invalid account'], JSON_PRETTY_PRINT));
                        
                    } catch (\Exception $e) {
                        (new ex($errorLog . '_p2pTransferExpired', 5, $e))->return();
                        exit(json_encode(['status' => 400, 'error' => LANG->get('TECHNICAL_ERROR')], JSON_PRETTY_PRINT));
                    }
                });

                $simpleRoute->route('/cancelP2PTransfer', function () use ($userData, $security, $route, $datehelper, $arrayAdapter, $money, $errorLog) {
                    try {

                        $csrf_name = $security->inputPost('csrf_name');
                        $csrf_value = $security->inputPost('csrf_value');

                        if ($guard->validateToken($csrf_name, $csrf_value) === false) {
                            exit(json_encode(['status' => 400, 'error' => (new ex($errorLog . '_cancelP2PTransfer', 6, LANG->get('EXTERNAL_REJECTED')))->return()], JSON_PRETTY_PRINT));
                        }

                        $account_id = $security->inputPost('account_id');
                        if($security->nothing($account_id)){
                            exit(json_encode(['status' => 400, 'error' => (new ex($errorLog.'_cancelP2PTransfer', 3, 'Invalid account'))->return()], JSON_PRETTY_PRINT));
                        }
                        $escrowId = $security->inputPost('escrow_id');
                       
                        if($security->nothing($escrowId)){
                            exit(json_encode(['status' => 400, 'error' => 'Invalid Params'], JSON_PRETTY_PRINT));
                        }

                        $accountModel = new Reflect('Account');
                        $account = $accountModel->getAccountsByAccountId($userData['authToken'], $account_id);

                        if (is_array($account) && !$arrayAdapter->isEmpty($account)) {

                            $escrowDB = new Reflect('Escrow');
                            $escrowData = $escrowDB->getEscrowById($userData['authToken'],$escrowId);
                            
                            if (!is_array($escrowData) || $arrayAdapter->isEmpty($escrowData)) {
                                exit(json_encode(['status' => 400, 'error' => 'Invalid Payment'], JSON_PRETTY_PRINT));
                            } else if($escrowData['status'] === 'user_confirmed'){
                                exit(json_encode(['status' => 400, 'error' => 'User confirmed payment cannot be cancelled'], JSON_PRETTY_PRINT));
                            } else if($escrowData['status'] === 'dispute'){
                                exit(json_encode(['status' => 400, 'error' => 'Disputed payment cannot be cancelled'], JSON_PRETTY_PRINT));
                            } else if($escrowData['status'] === 'cancelled'){
                                 exit(json_encode(['status' => 400, 'error' => 'Cancelled payment cannot be re-cancelled'], JSON_PRETTY_PRINT));
                            }

                            //Call smart contract to generate P2P
                            $walletManager = new MoonbeamWalletManager(CONFIG->get('zorah_environment'), 'moonbeam');
                            $walletManager->setGasWallet(CONFIG->get('relayer_key'));

                            $contractManager = new ContractManager($walletManager, CONFIG->get('moonbeam_escrow_contract'), SYSTEM_DIR . '/files/contracts/moonbeam_main_contract_abi.json');

                            $result = $contractManager->cancelEscrow($escrowData['escrow_id']);

                            if(!$result['success']){
                                exit(json_encode(['status' => 400, 'error' => 'Failed to cancel payment on blockchain. Please try again or contact support'], JSON_PRETTY_PRINT));
                            }

                            $reasons = 'Escrow cancelled by buyer without payment confirmation from seller';

                            $escrowUpdate = $escrowDB->updateEscrowStatus($userData['authToken'], $escrowId, 'cancelled', $datehelper->timestampTimeNow(), $reasons);

                            if ($escrowUpdate) {

                                $subject = 'Payment Cancelled | Escrow ' . $escrowData['escrow_id'];

                                $message = $route->textRender(file_get_contents(__DIR__ . '/../../../' . BODY_TEMPLATE_FOLDER . '/email/escrow_cancelled.html'), array(
                                    'logo' => APP_DOMAIN.'/asset/script/images/logo/logo.png',
                                    'lang_service_request' => 'Payment Request Cancelled',
                                    'trans_id' => $escrowData['escrow_id'],
                                    'support_mail' => SUPPORT_EMAIL,
                                    'footer' => COPYRIGHTS,
                                ));

                                (new Notification())->sendToEmail($subject, $userData['email'], $message);

                                $payload = [
                                    'escrow_id' => $escrowData['escrow_id'],
                                    'tx_hash' => $result['txHash'],
                                    'gas_fee' => $result['gasUsed'],
                                    'amount_usd' => $money->format($escrowData['amount_usd'], 'USD'),
                                    'deposit_currency' => $escrowData['deposit_currency'],
                                    'deposit_amount' =>  $money->format($escrowData['deposit_amount'], $escrowData['deposit_currency']),
                                    'status' => 'cancelled',
                                    'created_at' => $datehelper->formatDate($escrowData['created_at']),
                                    'expires_at' => $datehelper->formatDate($escrowData['expires_at']),
                                ];

                                $liquidityModel = new Reflect('Liquidity');
                                $liquidity = $liquidityModel->getLiquidityById($escrowData['liquidity_id']);

                                (new Notification())->sendToTelegram($liquidity['telegram_id'], $subject, $payload);

                                if(isset($liquidity['webhook'])){
                                    //Update webhook url if provider has webhook configured for this sale.
                                    $response = (new Notification())->sendToWebhook('escrowCancelled', $liquidity['webhook']['secret_key'], $liquidity['webhook']['url'], $payload, $liquidity['telegram_id']);

                                    if(!is_array($response) || $response['status'] !== true){
                                        //Log webhook failure but do not block the process
                                        (new ex($errorLog.'_cancelP2PTransfer', 3, 'Failed to send webhook to liquidity provider for escrow '.$escrowData['escrow_id'].'. Response: '.json_encode($response)))->return();
                                    }
                                }

                                exit(json_encode([
                                    'status' => 200, 
                                    'success' => true,
                                ], JSON_PRETTY_PRINT));
                            } else {
                                exit(json_encode(['status' => 400, 'error' => 'Failed to cancel escrow. Please try again.'], JSON_PRETTY_PRINT));
                            }
                        }
                        
                        exit(json_encode(['status' => 400, 'error' => 'Invalid account'], JSON_PRETTY_PRINT));
                        
                    } catch (\Exception $e) {
                        (new ex($errorLog . '_cancelP2PTransfer', 5, $e))->return();
                        exit(json_encode(['status' => 400, 'error' => LANG->get('TECHNICAL_ERROR')], JSON_PRETTY_PRINT));
                    }
                });

                $simpleRoute->route('/confirmP2PPayment', function () use ($userData, $security, $route, $datehelper, $arrayAdapter, $money, $errorLog) {
                    try {

                        $csrf_name = $security->inputPost('csrf_name');
                        $csrf_value = $security->inputPost('csrf_value');

                        if ($guard->validateToken($csrf_name, $csrf_value) === false) {
                            exit(json_encode(['status' => 400, 'error' => (new ex($errorLog . '_confirmP2PPayment', 6, LANG->get('EXTERNAL_REJECTED')))->return()], JSON_PRETTY_PRINT));
                        }

                        $account_id = $security->inputPost('account_id');
                        if($security->nothing($account_id)){
                            exit(json_encode(['status' => 400, 'error' => (new ex($errorLog.'_confirmP2PPayment', 3, 'Invalid account'))->return()], JSON_PRETTY_PRINT));
                        }
                        $escrowId = $security->inputPost('escrow_id');
                       
                        if($security->nothing($escrowId)){
                            exit(json_encode(['status' => 400, 'error' => 'Invalid Params'], JSON_PRETTY_PRINT));
                        }

                        $accountModel = new Reflect('Account');
                        $account = $accountModel->getAccountsByAccountId($userData['authToken'], $account_id);

                        if (is_array($account) && !$arrayAdapter->isEmpty($account)) {

                            $escrowDB = new Reflect('Escrow');
                            $escrowData = $escrowDB->getEscrowById($userData['authToken'],$escrowId);
                            
                            if (!is_array($escrowData) || $arrayAdapter->isEmpty($escrowData)) {
                                exit(json_encode(['status' => 400, 'error' => 'Invalid Payment'], JSON_PRETTY_PRINT));
                            } else if($escrowData['status'] === 'dispute'){
                                exit(json_encode(['status' => 400, 'error' => 'Disputed payment cannot be confirmed'], JSON_PRETTY_PRINT));
                            } else if($escrowData['status'] === 'user-'){
                                 exit(json_encode(['status' => 400, 'error' => 'Cancelled payment cannot be confirmed'], JSON_PRETTY_PRINT));
                            } else if($escrowData['buyer_confirmed'] === 1 && $escrowData['seller_confirmed'] === 1){
                                exit(json_encode(['status' => 400, 'error' => 'Payment already confirmed'], JSON_PRETTY_PRINT));
                            } else if($escrowData['buyer_confirmed'] ?? 0 === 1 && $userData['authToken'] === $escrowData['created_by']){
                                exit(json_encode(['status' => 400, 'error' => 'Payment already confirmed by you'], JSON_PRETTY_PRINT));
                            } else if($escrowData['seller_confirmed'] ?? 0 === 1 && $userData['authToken'] !== $escrowData['created_by']){
                                exit(json_encode(['status' => 400, 'error' => 'Payment already confirmed by liquidity provider'], JSON_PRETTY_PRINT));
                            }

                            $wallet = json_decode($account['wallet'], true);

                            $escrowData['buyer_confirmed'] = $escrowData['buyer_confirmed'] ?? 0;
                            $escrowData['seller_confirmed'] = $escrowData['seller_confirmed'] ?? 0;

                            if($wallet['address'] === $escrowData['buyer_wallet']){
                               $escrowData['buyer_confirmed'] = 1;
                            } else if($wallet['address'] === $escrowData['seller_wallet']){
                               $escrowData['seller_confirmed'] = 1;
                            } else {
                                exit(json_encode(['status' => 400, 'error' => 'You are not authorized to confirm this payment'], JSON_PRETTY_PRINT));
                            }

                            $escrowUpdate = $escrowDB->updateEscrowConfirmation($escrowData['created_by'], $escrowId, $escrowData['buyer_confirmed'], $escrowData['seller_confirmed'], $datehelper->timestampTimeNow());

                            if ($escrowUpdate) {

                                if($escrowData['seller_confirmed'] === 1){
                                    $escrowManager = new EscrowManager($escrowData);
                                }

                                $liquidityModel = new Reflect('Liquidity');
                                $liquidity = $liquidityModel->getLiquidityById($escrowData['liquidity_id']);

                                $payload = [
                                    'escrow_id' => $escrowData['escrow_id'],
                                    'amount_usd' => $money->format($escrowData['amount_usd'], 'USD'),
                                    'deposit_currency' => $escrowData['deposit_currency'],
                                    'deposit_amount' =>  $money->format($escrowData['deposit_amount'], $escrowData['deposit_currency']),
                                    'buyer_confirmed' => $escrowData['buyer_confirmed'],
                                    'seller_confirmed' => $escrowData['seller_confirmed'],
                                    'created_at' => $datehelper->formatDate($escrowData['created_at']),
                                    'expires_at' => $datehelper->formatDate($escrowData['expires_at']),
                                ];

                                if(isset($liquidity['webhook']) && $escrowData['buyer_confirmed'] === 1 && $escrowData['seller_confirmed'] === 0){
                                    //Update webhook url if provider has webhook configured for this sale.
                                    $response = (new Notification())->sendToWebhook('escrowBuyerConfirmed', $liquidity['webhook']['secret_key'], $liquidity['webhook']['url'], $payload, $liquidity['telegram_id']);

                                    if(!is_array($response) || $response['status'] !== true){
                                        //Log webhook failure but do not block the process
                                        (new ex($errorLog.'_confirmP2PPayment', 3, 'Failed to send webhook buyer confirmation for escrow '.$escrowData['escrow_id'].'. Response: '.json_encode($response)))->return();
                                    }
                                }

                                if($escrowData['buyer_confirmed'] === 1 && $escrowData['seller_confirmed'] === 0){
                                    (new Notification())->sendToTelegram($liquidity['telegram_id'], 'Buyer has confirmed payment for Escrow '.$escrowData['escrow_id'].'. Please confirm from your side to release the funds.', $payload);
                                }

                                $modal = file_get_contents(__DIR__ . '/../../../' . BODY_TEMPLATE_FOLDER . '/home/inline/confirmp2p.html');

                                $modal = $route->textRender($modal, array(
                                    'escrow_id' => $escrowData['escrow_id'],
                                    'deposit_amount' =>  $money->format($escrowData['deposit_amount'], $escrowData['deposit_currency']),
                                    'buyer_confirmed' => $escrowData['buyer_confirmed'],
                                    'seller_confirmed' => $escrowData['seller_confirmed'],
                                    'created_at' => $datehelper->formatDate($escrowData['created_at']),
                                    'expires_at' => $datehelper->formatDate($escrowData['expires_at']),
                                ));

                                

                                exit(json_encode([
                                    'status' => 200, 
                                    'success' => true,
                                    'escrowId' => $escrowData['escrow_id'],
                                    'modal' => $modal,
                                ], JSON_PRETTY_PRINT));
                            } else {
                                exit(json_encode(['status' => 400, 'error' => 'Failed to cancel escrow. Please try again.'], JSON_PRETTY_PRINT));
                            }
                        }
                        
                        exit(json_encode(['status' => 400, 'error' => 'Invalid account'], JSON_PRETTY_PRINT));
                        
                    } catch (\Exception $e) {
                        (new ex($errorLog . '__confirmP2PPayment', 5, $e))->return();
                        exit(json_encode(['status' => 400, 'error' => LANG->get('TECHNICAL_ERROR')], JSON_PRETTY_PRINT));
                    }
                });

                //DO NOT TOUCH
                /////////////////////////////////////////////////////////////////////////////
                $simpleRoute->dispatch($request);
                /////////////////////////////////////////////////////////////////////////////
            }
        }
        exit(json_encode(['status' => 400, 'error' => (new ex($errorLog, 3, LANG->get('REQUEST_ERROR')))->return()], JSON_PRETTY_PRINT));
    }
    exit(json_encode(['status' => 200, 'reload' => 'true'], JSON_PRETTY_PRINT));
} catch (\Exception $e) {
    exit(json_encode(['status' => 400, 'error' => (new ex($errorLog, 3, $e->getMessage()))->return()], JSON_PRETTY_PRINT));
}
