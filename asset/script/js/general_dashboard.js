var general = new General;
const BASE = general.getBase();

(function () {
    window.modalController = function (modalId, options = { bgClose: false, keyboard: false }) {
        return new Promise((resolve, reject) => {
            loadjs([BASE + "asset/script/js/components/modal.js"], {
                async: true,
                success: function () {
                    try {
                        const modalElement = document.getElementById(modalId);
                        if (!modalElement) {
                            console.error(`Modal with ID ${modalId} not found`);
                            reject(new Error(`Modal with ID ${modalId} not found`));
                            return;
                        }

                        const modal = new ModalController(modalElement, options).init();
                        resolve(modal);
                    } catch (error) {
                        console.error('Error initializing modal:', error);
                        reject(error);
                    }
                },
                error: function () {
                    console.error('Failed to load ModalController script');
                    reject(new Error('Failed to load ModalController script'));
                }
            });
        });
    };
})();

(function () {
    window.videoPlayer = function (videoClass, options = { fluid: true, responsive: true, controls: true }) {
        return new Promise((resolve, reject) => {
            // Validate video elements
            const videoElements = document.getElementsByClassName(videoClass);
            if (!videoElements || videoElements.length === 0) {
                console.error(`No video elements with class ${videoClass} found`);
                reject(new Error(`No video elements with class ${videoClass} found`));
                return;
            }
            loadjs([
                "https://vjs.zencdn.net/8.23.3/video-js.css",
                "https://vjs.zencdn.net/8.23.3/video.min.js"
            ], {
                async: true,
                success: function () {
                    try {
                        const players = [];
                        Array.from(videoElements).forEach(video => {
                            if (!video.classList.contains('video-js')) {
                                video.classList.add('video-js');
                            }
                            const player = videojs(video, options);
                            players.push(player);
                        });

                        resolve(players);
                    } catch (error) {
                        console.error('Error initializing Video.js:', error);
                        reject(error);
                    }
                },
                error: function (pathsNotFound) {
                    console.error('Failed to load Video.js resources:', pathsNotFound);
                    reject(new Error('Failed to load Video.js resources: ' + pathsNotFound.join(', ')));
                }
            });
        });
    };
})();

(function () {
    window.confirmController = function (options = { 
        theme: 'dark',
        title: 'Confirm Action',
        message: 'Are you sure you want to proceed?',
        yesText: 'Confirm',
        noText: 'Cancel',
        icon: null,
        showCloseButton: true,
        overlayClose: true,
        escapeClose: true
    }) {
        return new Promise((resolve, reject) => {
            // Check if ZorahDialog is already loaded
            if (window.ZorahDialog) {
                try {
                    window.ZorahDialog.confirm({
                        theme: options.theme || 'dark',
                        title: options.title,
                        message: options.message,
                        yesText: options.yesText || 'Confirm',
                        noText: options.noText || 'Cancel',
                        icon: options.icon,
                        showCloseButton: options.showCloseButton !== false,
                        overlayClose: options.overlayClose !== false,
                        escapeClose: options.escapeClose !== false
                    }).then(result => {
                        resolve(result);
                    }).catch(error => {
                        reject(error);
                    });
                } catch (error) {
                    console.error('Error showing confirmation dialog:', error);
                    reject(error);
                }
                return;
            }

            // Load the confirm component if not already loaded
            loadjs([BASE + "asset/script/js/components/confirm.js"], {
                async: true,
                success: function () {
                    try {
                        // Add a small delay to ensure script is fully parsed and executed
                        setTimeout(() => {
                            if (!window.ZorahDialog) {
                                throw new Error('ZorahDialog not available after loading script');
                            }

                            window.ZorahDialog.confirm({
                                theme: options.theme || 'dark',
                                title: options.title,
                                message: options.message,
                                yesText: options.yesText || 'Confirm',
                                noText: options.noText || 'Cancel',
                                icon: options.icon,
                                showCloseButton: options.showCloseButton !== false,
                                overlayClose: options.overlayClose !== false,
                                escapeClose: options.escapeClose !== false
                            }).then(result => {
                                resolve(result);
                            }).catch(error => {
                                reject(error);
                            });
                        }, 50);
                    } catch (error) {
                        console.error('Error initializing ZorahDialog:', error);
                        reject(error);
                    }
                },
                error: function () {
                    console.error('Failed to load ZorahDialog script');
                    reject(new Error('Failed to load ZorahDialog script'));
                }
            });
        });
    };
})();

(function () {
    window.bridgeController = function () {
        return new Promise((resolve, reject) => {
            if (window.ZorahBridge) {
                resolve(window.ZorahBridge);
                return;
            }

            loadjs([
                "https://cdnjs.cloudflare.com/ajax/libs/ethers/5.7.2/ethers.umd.min.js",
                BASE + "asset/script/js/bridge.js"
            ], {
                async: true,
                success: function () {
                    try {
                        const BridgeClass = window.AxelarBridge || window.ZorahBridge;
                        if (!BridgeClass) {
                            throw new Error('Bridge class not found after load');
                        }
                        // Normalize to ZorahBridge name for callers
                        window.ZorahBridge = BridgeClass;
                        resolve(BridgeClass);
                    } catch (err) {
                        reject(err);
                    }
                },
                error: function () {
                    reject(new Error('Failed to load bridge scripts'));
                }
            });
        });
    };
})();

function telephone(){
  if($('#phone').length){
        loadjs([BASE + "asset/plugin/tel/js/intlTelInput.min.js", BASE + "asset/plugin/tel/css/intlTelInput.min.css"], {
        async: true,
        success: function () {
          const input = document.querySelector("#phone");
          if(input){
            intl = window.intlTelInput(input, {
              preferredCountries: ["ng"],
              separateDialCode: true,
              initialCountry: "ng",
              loadUtils: () => import(BASE + "asset/plugin/tel/js/utils.js"),
            });
          }
        }
      });
    }
}

function formatMoneyInput(className, decimalPlaces = 2) {
    const inputs = document.querySelectorAll(`.${className}`);

    inputs.forEach(input => {
        input.addEventListener('input', function(e) {
            let value = e.target.value.replace(/[^0-9.]/g, '');
            const parts = value.split('.');
            if (parts.length > 2) {
                parts.length = 2;
                value = parts.join('.');
            }
            if (value) {
                let [integerPart, decimalPart = ''] = value.split('.');
                integerPart = parseInt(integerPart.replace(/^0+/, '')) || 0;
                integerPart = integerPart.toLocaleString('en-US', {
                    minimumFractionDigits: 0,
                    maximumFractionDigits: 0
                });
                if (decimalPart) {
                    decimalPart = decimalPart.slice(0, decimalPlaces);
                    decimalPart = decimalPart.padEnd(decimalPlaces, '0');
                    value = `${integerPart}.${decimalPart}`;
                } else {
                    value = `${integerPart}.${'0'.repeat(decimalPlaces)}`;
                }

                e.target.value = value;
            } else {
                e.target.value = `0.${'0'.repeat(decimalPlaces)}`;
            }
        });

        input.addEventListener('paste', function(e) {
            setTimeout(() => {
                input.dispatchEvent(new Event('input'));
            }, 0);
        });
    });
}