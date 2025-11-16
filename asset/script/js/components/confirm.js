/**
 * Modern Confirmation Dialog Component
 * Features:
 * - Tailwind CSS styling with theme support (dark, white, purple)
 * - Smooth animations and transitions
 * - Promise-based API
 * - Alert, Confirm, and Prompt dialogs
 * - Accessible keyboard navigation (Escape, Enter)
 * - Custom buttons and callbacks
 * - Form serialization support
 */

(function (global, factory) {
  const instance = factory();
  
  if (typeof exports === 'object' && typeof module !== 'undefined') {
    module.exports = instance;
  } else if (typeof define === 'function' && define.amd) {
    define([], function() { return instance; });
  } else {
    global.ZorahDialog = instance;
  }
  
  // Ensure it's available on window immediately
  if (typeof window !== 'undefined') {
    window.ZorahDialog = instance;
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  /**
   * Theme configurations with Tailwind CSS classes
   */
  const THEMES = {
    dark: {
      overlay: 'bg-black/70',
      modal: 'bg-black border border-purple-500/20',
      header: 'text-white',
      message: 'text-gray-300',
      input: 'bg-gray-800 border-gray-700 text-white placeholder-gray-500 focus:border-purple-500 focus:ring-purple-500/50',
      buttonPrimary: 'bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white',
      buttonSecondary: 'bg-gray-700 hover:bg-gray-600 text-gray-200',
      closeButton: 'text-gray-400 hover:text-white hover:bg-gray-800',
    },
    white: {
      overlay: 'bg-gray-900/50',
      modal: 'bg-white border border-gray-200 shadow-2xl',
      header: 'text-gray-900',
      message: 'text-gray-700',
      input: 'bg-white border-gray-300 text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:ring-blue-500/50',
      buttonPrimary: 'bg-blue-600 hover:bg-blue-700 text-white',
      buttonSecondary: 'bg-gray-200 hover:bg-gray-300 text-gray-700',
      closeButton: 'text-gray-400 hover:text-gray-600 hover:bg-gray-100',
    },
    purple: {
      overlay: 'bg-purple-900/60',
      modal: 'bg-gradient-to-br from-purple-950 to-indigo-950 border border-purple-400/30',
      header: 'text-purple-100',
      message: 'text-purple-200',
      input: 'bg-purple-900/50 border-purple-700 text-purple-100 placeholder-purple-400 focus:border-purple-400 focus:ring-purple-400/50',
      buttonPrimary: 'bg-gradient-to-r from-purple-500 to-pink-500 hover:from-purple-600 hover:to-pink-600 text-white',
      buttonSecondary: 'bg-purple-800/50 hover:bg-purple-700/50 text-purple-200',
      closeButton: 'text-purple-300 hover:text-purple-100 hover:bg-purple-800/50',
    },
  };

  /**
   * Serialize form data
   */
  function serializeForm(form) {
    const formData = new FormData(form);
    const data = {};
    
    for (const [key, value] of formData.entries()) {
      if (data[key]) {
        if (!Array.isArray(data[key])) {
          data[key] = [data[key]];
        }
        data[key].push(value);
      } else {
        data[key] = value;
      }
    }
    
    return data;
  }

  /**
   * Main Dialog class
   */
  class Dialog {
    constructor() {
      this.instances = [];
      this.zIndex = 10000;
      this.isOpening = false; // Prevent double-open
    }

    /**
    /**
     * Create modal overlay and container
     */
    createModal(options) {
      const theme = THEMES[options.theme] || THEMES.dark;
      
      // Create overlay
      const overlay = document.createElement('div');
      overlay.className = `fixed inset-0 z-[${this.zIndex}] flex items-center justify-center px-4 py-6 sm:p-4 ${theme.overlay} animate-fade-in`;
      overlay.style.cssText = `animation: fadeIn 0.3s cubic-bezier(0.4, 0, 0.2, 1); z-index: ${this.zIndex}`;
      
      // Create modal container with responsive sizing
      const modal = document.createElement('div');
      modal.className = `relative w-full max-w-[calc(100%-2rem)] sm:max-w-md rounded-2xl ${theme.modal} p-6 shadow-2xl transform animate-slide-up mx-auto`;
      modal.style.cssText = 'animation: slideUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); max-height: calc(100vh - 3rem); overflow-y: auto;';
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');
      modal.setAttribute('aria-labelledby', 'dialog-title');
      
      // Create close button if enabled
      if (options.showCloseButton) {
        const closeBtn = document.createElement('button');
        closeBtn.className = `absolute top-4 right-4 p-2 rounded-lg ${theme.closeButton} transition-all duration-200`;
        closeBtn.innerHTML = `
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        `;
        closeBtn.setAttribute('aria-label', 'Close dialog');
        closeBtn.addEventListener('click', () => this.close(overlay, false));
        modal.appendChild(closeBtn);
      }
      
      // Create icon if provided
      if (options.icon) {
        const iconDiv = document.createElement('div');
        iconDiv.className = 'flex justify-center mb-4';
        iconDiv.innerHTML = options.icon;
        modal.appendChild(iconDiv);
      }
      
      // Create title if provided
      if (options.title) {
        const title = document.createElement('h3');
        title.id = 'dialog-title';
        title.className = `text-xl font-bold mb-3 ${theme.header} text-center`;
        title.textContent = options.title;
        modal.appendChild(title);
      }
      
      // Create message
      if (options.message) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `mb-5 ${theme.message} text-center leading-relaxed`;
        
        if (options.unsafeMessage) {
          messageDiv.innerHTML = options.message;
        } else {
          messageDiv.textContent = options.message;
        }
        
        modal.appendChild(messageDiv);
      }
      
      // Create form if needed
      const form = document.createElement('form');
      form.className = 'space-y-4';
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = options.input ? serializeForm(form) : true;
        this.close(overlay, formData);
      });
      
      // Add input if provided
      if (options.input) {
        const inputWrapper = document.createElement('div');
        inputWrapper.className = 'space-y-2';
        
        if (options.label) {
          const label = document.createElement('label');
          label.className = `block text-sm font-medium ${theme.message}`;
          label.textContent = options.label;
          label.setAttribute('for', 'dialog-input');
          inputWrapper.appendChild(label);
        }
        
        const input = document.createElement('input');
        input.type = options.inputType || 'text';
        input.name = 'value';
        input.id = 'dialog-input';
        input.className = `w-full px-4 py-3 rounded-lg border ${theme.input} focus:outline-none focus:ring-2 transition-all duration-200`;
        input.placeholder = options.placeholder || '';
        input.value = options.value || '';
        
        if (options.inputAttrs) {
          Object.keys(options.inputAttrs).forEach(attr => {
            input.setAttribute(attr, options.inputAttrs[attr]);
          });
        }
        
        inputWrapper.appendChild(input);
        form.appendChild(inputWrapper);
      }
      
      // Add custom content if provided
      if (options.content) {
        const contentDiv = document.createElement('div');
        contentDiv.className = 'mb-4';
        
        if (typeof options.content === 'string') {
          contentDiv.innerHTML = options.content;
        } else if (options.content instanceof HTMLElement) {
          contentDiv.appendChild(options.content);
        }
        
        form.appendChild(contentDiv);
      }
      
      // Create buttons
      const buttonWrapper = document.createElement('div');
      buttonWrapper.className = `flex flex-col sm:flex-row gap-3 mt-6 justify-center`;
      
      options.buttons.forEach((btn, index) => {
        const button = document.createElement('button');
        button.type = btn.type || 'button';
        button.textContent = btn.text;
        
        const isPrimary = btn.className?.includes('primary') || btn.type === 'submit';
        const baseClasses = 'px-6 py-3 rounded-lg font-semibold transition-all duration-200 transform hover:scale-105 active:scale-95 focus:outline-none focus:ring-2 focus:ring-offset-2';
        const themeClasses = isPrimary ? theme.buttonPrimary : theme.buttonSecondary;
        
        button.className = `${baseClasses} ${themeClasses} ${btn.className || ''}`;
        
        button.addEventListener('click', (e) => {
          // Prevent multiple clicks during close animation
          if (button.disabled || overlay._isClosing) return;
          
          if (btn.click) {
            btn.click.call(button, e);
          }
          
          if (btn.type !== 'submit') {
            const result = btn.value !== undefined ? btn.value : false;
            this.close(overlay, result);
          }
        });
        
        if (index === 0 && options.focusFirstButton) {
          setTimeout(() => button.focus(), 100);
        }
        
        buttonWrapper.appendChild(button);
      });
      
      form.appendChild(buttonWrapper);
      modal.appendChild(form);
      overlay.appendChild(modal);
      
      // Store callback and options
      overlay._dialogCallback = options.callback;
      overlay._dialogBeforeClose = options.beforeClose;
      
      // Add keyboard support
      const handleKeyDown = (e) => {
        if (e.key === 'Escape' && options.escapeClose) {
          this.close(overlay, false);
        }
      };
      
      document.addEventListener('keydown', handleKeyDown);
      overlay._removeKeyListener = () => document.removeEventListener('keydown', handleKeyDown);
      
      // Click outside to close
      if (options.overlayClose) {
        overlay.addEventListener('click', (e) => {
          if (e.target === overlay) {
            this.close(overlay, false);
          }
        });
      }
      
      return { overlay, modal, form };
    }

    /**
     * Open a dialog
     */
    open(options = {}) {
      const defaults = {
        theme: 'dark',
        message: '',
        title: '',
        icon: null,
        unsafeMessage: false,
        input: false,
        inputType: 'text',
        label: '',
        placeholder: '',
        value: '',
        inputAttrs: {},
        content: null,
        buttons: [
          { text: 'OK', type: 'submit', className: 'primary', value: true }
        ],
        callback: () => {},
        beforeClose: null,
        showCloseButton: true,
        overlayClose: true,
        escapeClose: true,
        focusFirstButton: false,
        afterOpen: null,
      };
      
      const config = { ...defaults, ...options };
      
      // Prevent double-open (debounce)
      if (this.isOpening) return null;
      this.isOpening = true;
      setTimeout(() => { this.isOpening = false; }, 300);
      
      // Create and append modal
      const { overlay, modal, form } = this.createModal(config);
      document.body.appendChild(overlay);
      
      // Focus first input if present
      setTimeout(() => {
        const firstInput = form.querySelector('input, textarea, select');
        if (firstInput && config.input) {
          firstInput.focus();
        }
      }, 100);
      
      // Store instance
      const instance = {
        overlay,
        modal,
        form,
        close: (value) => this.close(overlay, value),
      };
      
      this.instances.push(instance);
      this.zIndex += 10;
      
      // Call afterOpen callback
      if (config.afterOpen) {
        config.afterOpen.call(instance);
      }
      
      return instance;
    }

    /**
     * Close a dialog
     */
    close(overlay, value) {
      if (!overlay || !overlay.parentNode) return;
      
      // Prevent multiple close calls
      if (overlay._isClosing) return;
      overlay._isClosing = true;
      
      const beforeClose = overlay._dialogBeforeClose;
      const callback = overlay._dialogCallback;
      
      // Call beforeClose if provided
      if (beforeClose) {
        const shouldClose = beforeClose(value);
        if (shouldClose === false) {
          overlay._isClosing = false;
          return;
        }
      }
      
      // Disable all buttons during close animation
      const buttons = overlay.querySelectorAll('button');
      buttons.forEach(btn => btn.disabled = true);
      
      // Animate out
      overlay.style.animation = 'fadeOut 0.25s cubic-bezier(0.4, 0, 0.2, 1)';
      const modal = overlay.querySelector('[role="dialog"]');
      if (modal) {
        modal.style.animation = 'slideDown 0.3s cubic-bezier(0.32, 0, 0.67, 0)';
      }
      
      setTimeout(() => {
        if (overlay._removeKeyListener) {
          overlay._removeKeyListener();
        }
        
        overlay.remove();
        
        // Remove from instances
        this.instances = this.instances.filter(inst => inst.overlay !== overlay);
        
        // Call callback
        if (callback) {
          callback(value);
        }
      }, 300);
    }

    /**
     * Confirm dialog
     */
    confirm(options) {
      if (typeof options === 'string') {
        options = { message: options };
      }
      
      const config = {
        ...options,
        icon: options.icon || `
          <div class="w-16 h-16 mx-auto rounded-full bg-yellow-500/20 flex items-center justify-center">
            <svg class="w-8 h-8 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
            </svg>
          </div>
        `,
        buttons: [
          { text: options.noText || 'Cancel', type: 'button', className: 'secondary', value: false },
          { text: options.yesText || 'Confirm', type: 'submit', className: 'primary', value: true },
        ],
      };
      
      return new Promise((resolve) => {
        config.callback = resolve;
        this.open(config);
      });
    }

    /**
     * Close all dialogs
     */
    closeAll() {
      this.instances.forEach(instance => {
        this.close(instance.overlay, false);
      });
    }
  }

  // Add CSS animations
  const style = document.createElement('style');
  style.textContent = `
    @keyframes fadeIn {
      from { 
        opacity: 0; 
      }
      to { 
        opacity: 1; 
      }
    }
    
    @keyframes fadeOut {
      from { 
        opacity: 1; 
      }
      to { 
        opacity: 0; 
      }
    }
    
    @keyframes slideUp {
      0% {
        opacity: 0;
        transform: translateY(30px) scale(0.9);
      }
      50% {
        opacity: 0.8;
        transform: translateY(-5px) scale(1.02);
      }
      100% {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }
    
    @keyframes slideDown {
      0% {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
      50% {
        opacity: 0.5;
        transform: translateY(10px) scale(0.98);
      }
      100% {
        opacity: 0;
        transform: translateY(30px) scale(0.9);
      }
    }
  `;
  document.head.appendChild(style);

  // Create and return singleton instance
  const instance = new Dialog();
  
  // Also expose as window.ZorahDialog for global access
  if (typeof window !== 'undefined') {
    window.ZorahDialog = instance;
  }
  
  return instance;
});
