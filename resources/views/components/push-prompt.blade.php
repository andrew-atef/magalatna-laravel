{{-- OneSignal Web Push Prompt — Non-intrusive, zero-CLS, zero-TBT, iOS PWA aware --}}
{{-- Only renders when ONESIGNAL_APP_ID is configured and NOT on admin/filament routes --}}
@php
    $appId = config('services.onesignal.app_id');
@endphp
@if ($appId)
<div id="magalatna-push-prompt" class="hidden" data-app-id="{{ $appId }}" x-data="pushPrompt()">
    {{-- Mobile (< lg): Floating pill ABOVE bottom bar --}}
    <div class="lg:hidden fixed inset-x-3 bottom-[74px] z-40 transition-opacity duration-300" x-show="!hidden" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:leave="transition ease-in duration-200" x-transition:leave-end="opacity-0" x-cloak>
        <div class="bg-[#023b55] text-white rounded-2xl px-3.5 py-2.5 shadow-xl border border-white/10 flex items-center justify-between gap-2 max-w-xs mx-auto">
            <div class="flex items-center gap-2 flex-1 min-w-0">
                <svg class="h-5 w-5 flex-shrink-0 text-[#fcc023]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <span class="text-xs font-medium truncate" x-text="copy.text"></span>
            </div>
            <div class="flex items-center gap-1.5 flex-shrink-0">
                <button type="button" class="bg-[#039652] hover:bg-[#027a42] text-white rounded-xl px-3 py-1.5 text-xs font-black transition-colors whitespace-nowrap" @click="subscribe()" x-text="copy.cta"></button>
                <button type="button" class="text-white/70 hover:text-white transition-colors p-1 rounded-lg" @click="dismiss()" aria-label="إغلاق"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
        </div>
    </div>

    {{-- Desktop (lg+): Floating card in bottom-left (RTL reading edge on right) --}}
    <div class="hidden lg:flex fixed bottom-6 left-6 z-40 max-w-sm transition-opacity duration-300" x-show="!hidden" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:leave="transition ease-in duration-200" x-transition:leave-end="opacity-0" x-cloak>
        <div class="bg-white rounded-2xl shadow-xl border border-slate-200 p-4 flex flex-col gap-3 w-full" dir="rtl">
            <div class="flex items-start justify-between gap-2">
                <div class="flex items-center gap-2 flex-1">
                    <span class="h-8 w-8 flex-shrink-0 items-center justify-center rounded-xl bg-[#023b55]/10 text-[#023b55]"><svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg></span>
                    <div>
                        <p class="text-sm font-black text-[#023b55]" x-text="copy.desktopTitle"></p>
                        <p class="text-xs text-slate-500" x-text="copy.desktopSubtext"></p>
                    </div>
                </div>
                <button type="button" class="text-slate-400 hover:text-slate-600 transition-colors p-1 rounded-lg flex-shrink-0" @click="dismiss()" aria-label="إغلاق"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <button type="button" class="bg-[#039652] hover:bg-[#027a42] text-white rounded-xl px-4 py-2 text-sm font-black transition-colors w-full" @click="subscribe()" x-text="copy.desktopCta"></button>
        </div>
    </div>

    {{-- iOS Safari non-standalone instruction card (toggles inline) --}}
    <div class="lg:hidden fixed inset-x-3 bottom-[74px] z-40 transition-opacity duration-300" x-show="showIosInstructions" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:leave="transition ease-in duration-200" x-transition:leave-end="opacity-0" x-cloak>
        <div class="bg-white rounded-2xl shadow-xl border border-slate-200 p-4 flex flex-col gap-3" dir="rtl">
            <div class="flex items-center justify-between gap-2">
                <span class="text-sm font-black text-[#023b55] flex-1">تفعيل الإشعارات على آيفون</span>
                <button type="button" class="text-slate-400 hover:text-slate-600 transition-colors p-1 rounded-lg" @click="showIosInstructions = false" aria-label="إغلاق"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <p class="text-xs text-slate-600 leading-relaxed" x-html="copy.iosInstructions"></p>
            <button type="button" class="bg-[#039652] hover:bg-[#027a42] text-white rounded-xl px-4 py-2 text-sm font-black transition-colors w-full mt-1" @click="showIosInstructions = false; subscribe()">فهمت، تفعيل الآن</button>
        </div>
    </div>

    {{-- Success toast (2s) --}}
    <div class="fixed inset-x-3 bottom-[74px] z-50 transition-opacity duration-300 lg:hidden" x-show="showToast" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:leave="transition ease-in duration-200" x-transition:leave-end="opacity-0" x-cloak>
        <div class="bg-[#039652] text-white rounded-2xl px-4 py-3 shadow-xl border border-white/10 flex items-center justify-center gap-2 max-w-xs mx-auto">
            <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            <span class="text-sm font-medium" x-text="copy.toast"></span>
        </div>
    </div>
</div>

@endif

<script>
// Alpine.js component for push prompt logic
document.addEventListener('alpine:init', () => {
    Alpine.data('pushPrompt', () => ({
        // State
        hidden: true,
        showIosInstructions: false,
        showToast: false,
        appId: null,
        onesignalLoaded: false,
        onesignalInitPromise: null, // Promise-based lock for atomic init
        changeListenerAttached: false,
        copy: {
            text: 'ابعتلي تنبيه لما مجلة جديدة تنزل',
            cta: 'تفعيل',
            desktopTitle: 'تنبيهات العروض الفورية 🔔',
            desktopSubtext: 'ابعتلي تنبيه أول ما مجلة جديدة تنزل',
            desktopCta: 'تفعيل التنبيهات',
            iosInstructions: 'لتفعيل الإشعارات على آيفون: 1️⃣ اضغط زر المشاركة بالأسفل ⬆️ ثم اختر «إضافة إلى الشاشة الرئيسية» ➕ — 2️⃣ افتح «مجلاتنا» من الشاشة الرئيسية واضغط «تفعيل».',
            toast: '✅ تم تفعيل التنبيهات بنجاح!'
        },

        // Init
        init() {
            console.log('[PushPrompt] init() called');
            this.appId = this.$el.dataset.appId;
            this.checkAndShow();
        },

        // Check if we should show the prompt
        checkAndShow() {
            console.log('[PushPrompt] checkAndShow() called');
            
            // DEBUG MODE: Force show if ?push_debug=1 in URL
            const isDebug = new URLSearchParams(window.location.search).has('push_debug');
            if (isDebug) {
                console.log('[PushPrompt] DEBUG MODE: forcing show');
                this.hidden = false;
                this.lazyLoadOneSignal();
                return;
            }

            // Already subscribed?
            const subscribed = localStorage.getItem('magalatna_push_subscribed') === '1';
            console.log('[PushPrompt] subscribed:', subscribed);
            if (subscribed) {
                this.hidden = true;
                console.log('[PushPrompt] hidden (subscribed)');
                return;
            }

            // Notification permission already decided (granted/denied) - except iOS non-standalone
            if ('Notification' in window) {
                const perm = Notification.permission;
                console.log('[PushPrompt] Notification.permission:', perm);
                if (perm === 'granted' || perm === 'denied') {
                    // On iOS non-standalone, Notification API exists but push isn't available
                    const isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
                    const isStandalone = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
                    if (!(isIos && !isStandalone)) {
                        this.hidden = true;
                        console.log('[PushPrompt] hidden (permission decided)');
                        return;
                    }
                }
            }

            // Dismissed until date?
            const dismissedUntil = localStorage.getItem('magalatna_push_dismissed_until');
            console.log('[PushPrompt] dismissedUntil:', dismissedUntil, 'now:', Date.now(), 'expired:', dismissedUntil && parseInt(dismissedUntil, 10) > Date.now());
            if (dismissedUntil && parseInt(dismissedUntil, 10) > Date.now()) {
                this.hidden = true;
                console.log('[PushPrompt] hidden (dismissed)');
                return;
            }

            // Show after engagement delay (3.5s or first scroll)
            console.log('[PushPrompt] scheduling show...');
            let shown = false;
            const showPrompt = () => {
                if (shown) return;
                shown = true;
                this.hidden = false;
                this.lazyLoadOneSignal();
                console.log('[PushPrompt] showPrompt fired, hidden =', this.hidden);
            };

            const scrollHandler = () => {
                window.removeEventListener('scroll', scrollHandler, { passive: true });
                showPrompt();
            };

            // 3.5s timeout OR first scroll, whichever comes first
            const timeoutId = setTimeout(showPrompt, 3500);
            window.addEventListener('scroll', scrollHandler, { passive: true, once: true });

            // Early click on CTA triggers immediate load
            this.$watch('hidden', (val) => {
                if (!val && !this.onesignalLoaded) {
                    this.lazyLoadOneSignal();
                }
            });
        },

        // Lazy-load OneSignal SDK (TBT = 0ms)
        async lazyLoadOneSignal() {
            if (this.onesignalLoaded) return;
            this.onesignalLoaded = true;
            console.log('[PushPrompt] lazyLoadOneSignal() called');

            const loadScript = () => {
                return new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.src = 'https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js';
                    script.async = true;
                    script.onload = resolve;
                    script.onerror = reject;
                    document.head.appendChild(script);
                });
            };

            try {
                if ('requestIdleCallback' in window) {
                    await new Promise((resolve) => requestIdleCallback(resolve, { timeout: 2500 }));
                } else {
                    await new Promise((resolve) => window.addEventListener('load', resolve, { once: true }));
                }
                await loadScript();
                await this.initOneSignal();
            } catch (e) {
                console.warn('OneSignal SDK load failed:', e);
            }
        },

        // Initialize OneSignal after SDK loads — atomic with Promise lock
        async initOneSignal() {
            if (!window.OneSignal) {
                await new Promise(r => setTimeout(r, 100));
            }
            if (!window.OneSignal) return;

            // Atomic init lock: only ONE caller proceeds, others wait for result
            if (window.__onesignal_init_promise) {
                try {
                    await window.__onesignal_init_promise;
                    if (!this.changeListenerAttached) this.attachChangeListener();
                } catch (e) {
                    console.warn('OneSignal init failed (waited for existing):', e);
                }
                return;
            }

            // Already initialized by another page load?
            if (window.__onesignal_initialized) {
                if (!this.changeListenerAttached) this.attachChangeListener();
                return;
            }

            // Create the init promise and store globally
            window.__onesignal_init_promise = (async () => {
                try {
                    // Register service worker FIRST (required for web push)
                    if ('serviceWorker' in navigator) {
                        try {
                            await navigator.serviceWorker.register('/OneSignalSDKWorker.js', { scope: '/' });
                        } catch (e) {
                            console.warn('Service worker registration failed:', e);
                        }
                    }

                    // Initialize OneSignal
                    await window.OneSignal.init({
                        appId: this.appId,
                        allowLocalhostAsSecureOrigin: true,
                        autoRegister: false,
                        promptOptions: {
                            slidedown: { enabled: false },
                            native: { enabled: false },
                        },
                    });

                    window.__onesignal_initialized = true;
                    this.attachChangeListener();
                } catch (e) {
                    console.warn('OneSignal init failed:', e);
                    if (e.message?.includes('already initialized') || e.message?.includes('SDK already initialized')) {
                        window.__onesignal_initialized = true;
                        this.attachChangeListener();
                    } else {
                        throw e;
                    }
                } finally {
                    window.__onesignal_init_promise = null;
                }
            })();

            try {
                await window.__onesignal_init_promise;
            } catch (e) {
                console.warn('OneSignal init failed (await):', e);
            }
        },

        attachChangeListener() {
            if (this.changeListenerAttached) return;
            this.changeListenerAttached = true;
            try {
                window.OneSignal.User.PushSubscription.addEventListener('change', (event) => {
                    if (event.current.optedIn) {
                        localStorage.setItem('magalatna_push_subscribed', '1');
                        this.hideAllAndToast();
                    }
                });
            } catch (e) {
                console.warn('OneSignal change listener failed:', e);
            }
        },

        // Subscribe: request native permission
        async subscribe() {
            if (!window.OneSignal) {
                await this.lazyLoadOneSignal();
                await new Promise(r => setTimeout(r, 200));
            }
            if (!window.OneSignal) return;

            // iOS Safari non-standalone detection
            const isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
            const isStandalone = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;

            if (isIos && !isStandalone) {
                this.showIosInstructions = true;
                return;
            }

            try {
                await window.OneSignal.User.PushSubscription.optIn();
            } catch (e) {
                console.warn('OneSignal optIn failed:', e);
            }
        },

        // Dismiss for 7 days
        dismiss() {
            const until = Date.now() + 7 * 86400 * 1000;
            localStorage.setItem('magalatna_push_dismissed_until', String(until));
            this.hidden = true;
        },

        // Hide all UI and show success toast
        hideAllAndToast() {
            this.hidden = true;
            this.showIosInstructions = false;
            this.showToast = true;
            setTimeout(() => {
                this.showToast = false;
            }, 2000);
        },
    }));
});
</script>