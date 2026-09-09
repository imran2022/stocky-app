<?php

use App\Http\Controllers\Admin\StoreSettingsController as AdminStoreSettings;
use App\Http\Controllers\Api\Store\AccountPagesController;
use App\Http\Controllers\Api\Store\CheckoutController;
use App\Http\Controllers\Api\Store\CustomerLoyaltyController;
use App\Http\Controllers\Api\Store\CustomerReturnsController;
use App\Http\Controllers\Api\Store\CustomerReviewsController;
use App\Http\Controllers\Api\Store\CustomerWalletController;
use App\Http\Controllers\Api\Store\QuoteRequestsController;
use App\Http\Controllers\Api\Store\MessageController;
use App\Http\Controllers\Api\Store\MyOrdersApiController;
use App\Http\Controllers\Api\Store\NewsletterController;
use App\Http\Controllers\QuickBooksController;
use App\Http\Controllers\RealEstateStoreController;
use App\Http\Controllers\StoreAuthController;
use App\Http\Controllers\StoreFrontController;
use App\Http\Controllers\WishlistController;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
 */

// ------------------------------------------------------------------\\
// Passport::routes();

// Login route will be defined explicitly below with middleware

Route::get('password/find/{token}', 'PasswordResetController@find');

// PWA manifest — generated from the configured app name (Appearance Settings)
// so the install prompt / installed app reflects the tenant's name instead of a
// hardcoded "Stocky". Kept at /manifest.webmanifest so existing <link> tags and
// the service worker precache list need no changes.
Route::get('manifest.webmanifest', function () {
    $appName = optional(\App\Models\Setting::first())->app_name ?: 'Stocky';

    // short_name must be brief for app launchers: use the part before a "|"
    // separator if present (e.g. "Stocky | Ultimate Inventory With POS" -> "Stocky").
    $shortName = trim(explode('|', $appName)[0]);
    if ($shortName === '') {
        $shortName = $appName;
    }

    $manifest = [
        'name' => $appName,
        'short_name' => $shortName,
        'description' => $appName,
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'orientation' => 'any',
        'background_color' => '#ffffff',
        'theme_color' => '#2f3640',
        'lang' => 'en',
        'dir' => 'ltr',
        'icons' => [
            ['src' => '/pwa_images/pwa-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/pwa_images/pwa-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/pwa_images/pwa-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
            ['src' => '/pwa_images/pwa-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ];

    return response()->json($manifest, 200, [
        'Content-Type' => 'application/manifest+json',
        'Cache-Control' => 'no-cache',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
})->name('pwa.manifest');

// Route::middleware(['web','auth:web','Is_Active'])->group(function () {
//     Route::get('/admin/store/settings', [AdminStoreSettings::class, 'show']);
//     Route::post('/admin/store/settings', [AdminStoreSettings::class, 'update']);
// });

$installed = Storage::disk('public')->exists('installed');

// ------------------------------------------------------------------\\
// ONLINE STORE ROUTES (Only if installed)
if ($installed === true) {

    Route::middleware(['web', 'request.safety', 'store.enabled'])->group(function () {

        Route::prefix('online_store')->group(function () {

            Route::get('/lang/{locale}', function ($locale) {
                $supported = ['en', 'fr', 'es', 'ar'];

                // Use provided locale if supported, otherwise fallback to 'en'
                $chosen = in_array($locale, $supported, true) ? $locale : 'en';

                // Store in session
                session(['locale' => $chosen]);

                // Optionally persist for a year via cookie
                Cookie::queue('locale', $chosen, 60 * 24 * 365, '/');

                return back();
            })->name('lang.switch');

            Route::get('/', [StoreFrontController::class, 'index'])->name('store.index');
            Route::get('/shop', [StoreFrontController::class, 'shop'])->name('store.shop');
            Route::get('/flash-sales', [StoreFrontController::class, 'flashSales'])->name('store.flash_sales');
            Route::get('/contact', [StoreFrontController::class, 'contact'])->name('store.contact');

            // Public product reviews (approved only)
            Route::get('/products/{id}/reviews', [CustomerReviewsController::class, 'productReviews'])
                ->name('store.product.reviews');

            // Quotation request (service / classified-ad products; guests allowed)
            Route::post('/quote-request', [QuoteRequestsController::class, 'store'])
                ->middleware('throttle:8,1')->name('store.quote.request');
            Route::post('/store/orders', [CheckoutController::class, 'store'])->name('store.orders.store');
            Route::post('/store/payment-intent', [CheckoutController::class, 'createPaymentIntent'])->name('store.payment.intent');
            Route::post('/store/checkout-quote', [CheckoutController::class, 'quote'])->name('store.checkout.quote');
            // PayPal redirect endpoints (no auth middleware: the pending order is
            // resolved by the unguessable ?token={paypal_order_id}, so capture
            // still works even if the store session was lost mid-redirect).
            Route::get('/store/paypal/return', [CheckoutController::class, 'paypalReturn'])->name('store.paypal.return');
            Route::get('/store/paypal/cancel', [CheckoutController::class, 'paypalCancel'])->name('store.paypal.cancel');
            // Paystack has a single callback URL; success/failure/abandoned is
            // decided by the server-side Verify call, never the redirect.
            Route::get('/store/paystack/return', [CheckoutController::class, 'paystackReturn'])->name('store.paystack.return');
            // Flutterwave redirects back with ?status&tx_ref&transaction_id;
            // paid/failed is decided by the server-side verify, never the query.
            Route::get('/store/flutterwave/return', [CheckoutController::class, 'flutterwaveReturn'])->name('store.flutterwave.return');
            // Razorpay Payment Link callback; paid/failed is decided by the
            // server-side link fetch, never the query params.
            Route::get('/store/razorpay/return', [CheckoutController::class, 'razorpayReturn'])->name('store.razorpay.return');
            Route::get('/collections/{slug}', [StoreFrontController::class, 'collection'])->name('store.collection.show');

            // Full product detail page (by id) + product comparison
            Route::get('/product/{id}', [StoreFrontController::class, 'productShow'])->whereNumber('id')->name('store.product.show');
            Route::get('/compare', [StoreFrontController::class, 'compare'])->name('store.compare');

            // CMS content pages (managed from the admin Pages screen)
            Route::get('/pages/{slug}', [StoreFrontController::class, 'page'])->name('store.page');

            // Vehicle Fitment: selector + My Garage (session-based; garage
            // rows require the store login). 404s while the feature is off.
            Route::get('/vehicle/options', [\App\Http\Controllers\StoreVehicleController::class, 'options'])->name('store.vehicle.options');
            Route::post('/vehicle', [\App\Http\Controllers\StoreVehicleController::class, 'select'])->name('store.vehicle.select');
            Route::post('/vehicle/clear', [\App\Http\Controllers\StoreVehicleController::class, 'clear'])->name('store.vehicle.clear');
            Route::post('/garage/{id}/select', [\App\Http\Controllers\StoreVehicleController::class, 'selectGarage'])->whereNumber('id')->name('store.garage.select');
            Route::delete('/garage/{id}', [\App\Http\Controllers\StoreVehicleController::class, 'destroyGarage'])->whereNumber('id')->name('store.garage.destroy');

            // Wishlist mutations (return 401 JSON for guests instead of a redirect)
            Route::get('/wishlist/ids', [WishlistController::class, 'ids'])->name('store.wishlist.ids');
            Route::post('/wishlist/toggle', [WishlistController::class, 'toggle'])->name('store.wishlist.toggle');
            Route::post('/wishlist/{productId}/remove', [WishlistController::class, 'remove'])->whereNumber('productId')->name('store.wishlist.remove');

            // Real Estate theme storefront (active when StoreSetting->theme = real_estate)
            Route::get('/properties', [RealEstateStoreController::class, 'listings'])->name('store.realestate.listings');
            Route::post('/properties/inquiry', [RealEstateStoreController::class, 'inquiry'])->name('store.realestate.inquiry');
            Route::get('/properties/{slug}', [RealEstateStoreController::class, 'show'])->name('store.realestate.show');

            Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe'])->name('newsletter.subscribe');
            Route::post('/contact/send', [MessageController::class, 'store'])->name('store.contact.send');
            Route::get('/search/suggestions', [StoreFrontController::class, 'searchSuggestions'])->name('store.search.suggestions');

            // Account pages (require login on 'store' guard)
            Route::middleware(['web', 'auth:store'])->group(function () {
                Route::view('/checkout', 'store.checkout')->name('checkout');
                Route::view('/thank-you', 'store.thank-you')->name('store.thankyou');

                Route::get('/account', [AccountPagesController::class, 'account'])->name('account');

                Route::put('/account', [AccountPagesController::class, 'update'])->name('account.update');

                Route::put('/account/address', [AccountPagesController::class, 'updateAddress'])->name('account.address.update');

                Route::get('/account/wishlist', [StoreFrontController::class, 'wishlist'])->name('store.wishlist');

                Route::get('/account/orders', [AccountPagesController::class, 'orders'])
                    ->name('account.orders');

                Route::get('/account/orders/{id}', function ($id) {
                    // $s is likely shared via view composer; if not, fetch StoreSetting here
                    return view('store.order-show', ['id' => $id]);
                })->name('account.order.show');

                Route::get('/account/orders/{id}/invoice', [CheckoutController::class, 'invoice'])
                    ->name('account.order.invoice');

                // Returns / cancellations (customer requests only)
                Route::get('/account/orders/{id}/return-eligibility', [CustomerReturnsController::class, 'eligibility'])
                    ->name('account.order.return.eligibility');
                Route::post('/account/orders/{id}/cancel', [CustomerReturnsController::class, 'cancel'])
                    ->name('account.order.cancel');
                Route::post('/account/orders/{id}/return', [CustomerReturnsController::class, 'requestReturn'])
                    ->name('account.order.return');

                // Product reviews (verified purchase)
                Route::get('/account/orders/{id}/reviewable', [CustomerReviewsController::class, 'reviewable'])
                    ->name('account.order.reviewable');
                Route::post('/account/orders/{id}/review', [CustomerReviewsController::class, 'submit'])
                    ->name('account.order.review');

                // Customer's own orders (JSON for the account orders table)
                Route::get('/my/orders', [MyOrdersApiController::class, 'index'])
                    ->name('my_orders.index');
                // (Optional) details endpoint if you add a “view” drawer/page:
                Route::get('/my/orders/{id}', [MyOrdersApiController::class, 'show'])
                    ->name('my_orders.show');

                // My Returns — dedicated page listing all return/cancellation requests
                Route::view('/account/returns', 'store.account-returns')->name('account.returns');
                Route::get('/my/returns', [CustomerReturnsController::class, 'myReturns'])
                    ->name('my_returns.index');

                // My Wallet — balance, history, redeem gift cards, request withdrawals
                Route::view('/account/wallet', 'store.account-wallet')->name('account.wallet');
                Route::get('/my/wallet', [CustomerWalletController::class, 'summary'])->name('my_wallet.summary');
                Route::get('/my/wallet/transactions', [CustomerWalletController::class, 'transactions'])->name('my_wallet.transactions');
                Route::post('/my/wallet/redeem', [CustomerWalletController::class, 'redeem'])->name('my_wallet.redeem');
                Route::post('/my/wallet/withdraw', [CustomerWalletController::class, 'withdraw'])->name('my_wallet.withdraw');

                // My Loyalty — points balance, redeemable rewards, redemption history
                Route::view('/account/rewards', 'store.account-loyalty')->name('account.rewards');
                Route::get('/my/loyalty', [CustomerLoyaltyController::class, 'summary'])->name('my_loyalty.summary');
                Route::post('/my/loyalty/redeem', [CustomerLoyaltyController::class, 'redeem'])->name('my_loyalty.redeem');
                Route::get('/my/loyalty/redemptions', [CustomerLoyaltyController::class, 'redemptions'])->name('my_loyalty.redemptions');
            });

            // Auth pages (only for guests of 'store' guard)
            Route::middleware('guest:store')->group(function () {
                Route::get('/login', [StoreAuthController::class, 'showLogin'])->name('store.login.show');
                Route::post('/login', [StoreAuthController::class, 'login'])->name('store.login');

                Route::get('/register', [StoreAuthController::class, 'showRegister'])->name('store.register.show');
                Route::post('/register', [StoreAuthController::class, 'register'])->name('store.register');

                // Password recovery
                Route::get('/forgot-password', [StoreAuthController::class, 'showForgotPassword'])->name('store.password.request');
                Route::post('/forgot-password', [StoreAuthController::class, 'sendResetLink'])
                    ->middleware('throttle:5,1')->name('store.password.email');
                Route::get('/reset-password/{token}', [StoreAuthController::class, 'showResetForm'])->name('store.password.reset');
                Route::post('/reset-password', [StoreAuthController::class, 'resetPassword'])
                    ->middleware('throttle:5,1')->name('store.password.update');

            });

            // Email verification (signed link sent by email; works logged in or out)
            Route::get('/email/verify/{id}/{hash}', [StoreAuthController::class, 'verifyEmail'])
                ->middleware('throttle:12,1')->name('store.verification.verify');
            Route::post('/email/resend', [StoreAuthController::class, 'resendVerification'])
                ->middleware('throttle:4,1')->name('store.verification.resend');

            // Logout (must be logged in on 'store')
            Route::post('/logout', [StoreAuthController::class, 'logout'])
                ->middleware('auth:store')->name('store.logout');

        });
    });

} else {
    // if not installed: redirect all /online_store requests to /setup
    Route::any('/online_store/{any?}', function () {
        return redirect('/setup');
    })->where('any', '.*');
}

// ------------------------------------------------------------------\\

$installed = Storage::disk('public')->exists('installed');

if ($installed === false) {
    Route::get('/setup', [
        'uses' => 'SetupController@viewCheck',
    ])->name('setup');

    Route::get('/setup/step-1', [
        'uses' => 'SetupController@viewStep1',
    ]);

    Route::post('/setup/step-2', [
        'as' => 'setupStep1', 'uses' => 'SetupController@setupStep1',
    ]);

    Route::post('/setup/testDB', [
        'as' => 'testDB', 'uses' => 'TestDbController@testDB',
    ]);

    Route::get('/setup/step-2', [
        'uses' => 'SetupController@viewStep2',
    ]);

    Route::get('/setup/step-3', [
        'uses' => 'SetupController@viewStep3',
    ]);

    Route::get('/setup/finish', function () {

        return view('setup.finishedSetup');
    });

    Route::get('/setup/getNewAppKey', [
        'as' => 'getNewAppKey', 'uses' => 'SetupController@getNewAppKey',
    ]);

    Route::get('/setup/getPassport', [
        'as' => 'getPassport', 'uses' => 'SetupController@getPassport',
    ]);

    Route::get('/setup/getMegrate', [
        'as' => 'getMegrate', 'uses' => 'SetupController@getMegrate',
    ]);

    Route::post('/setup/step-3', [
        'as' => 'setupStep2', 'uses' => 'SetupController@setupStep2',
    ]);

    Route::post('/setup/step-4', [
        'as' => 'setupStep3', 'uses' => 'SetupController@setupStep3',
    ]);

    Route::post('/setup/step-5', [
        'as' => 'setupStep4', 'uses' => 'SetupController@setupStep4',
    ]);

    Route::post('/setup/lastStep', [
        'as' => 'lastStep', 'uses' => 'SetupController@lastStep',
    ]);

    Route::get('setup/lastStep', function () {
        return redirect('/setup', 301);
    });

} else {
    Route::any('/setup/{vue}', function () {
        abort(403);
    });
}

Route::group(['middleware' => ['web', 'auth:web', 'Is_Active']], function () {

    // Vue 3 + Ant Design app ("Stocky Next") — catch-all under /next/, registered
    // before the SPA /{vue?} wildcard so it isn't swallowed by it.
    Route::view('/next/{any?}', 'next')->where('any', '.*')->name('next');
    Route::redirect('/dashboard-next', '/next/dashboard');

    // QuickBooks OAuth + status
    Route::get('/quickbooks/connect', [QuickBooksController::class, 'connect'])->name('quickbooks.connect');
    Route::get('/quickbooks/callback', [QuickBooksController::class, 'callback'])->name('quickbooks.callback');

    // Google Calendar OAuth (for booking events)
    Route::get('/google-calendar/connect', [\App\Http\Controllers\GoogleCalendarConnectController::class, 'connect'])->name('google_calendar.connect');
    Route::get('/google-calendar/callback', [\App\Http\Controllers\GoogleCalendarConnectController::class, 'callback'])->name('google_calendar.callback');
    Route::get('/google-calendar/disconnect', [\App\Http\Controllers\GoogleCalendarConnectController::class, 'disconnect'])->name('google_calendar.disconnect');
});

// ------------------------------------------------------------------\\
// Client Portal - if no portal auth, send directly to login (no Vue app load)
Route::get('/portal/{vue?}', function (\Illuminate\Http\Request $request, $vue = null) {
    $installed = Storage::disk('public')->exists('installed');
    if ($installed === false) {
        return redirect('/setup');
    }
    $guestSegments = ['login', 'set-password'];
    $isGuestPath = in_array($vue, $guestSegments, true);
    if (! \Illuminate\Support\Facades\Auth::guard('portal')->check() && ! $isGuestPath) {
        return redirect('/portal/login');
    }
    return view('portal');
})->where('vue', '.*')->middleware(['web']);

// ------------------------------------------------------------------\\

// Returns the current session's CSRF token. Used by the login form to refresh
// the _token field right before submit, so a stale cached page cannot cause 419.
// Must be registered BEFORE the authenticated /{vue?} wildcard below, otherwise
// the wildcard claims /csrf-token and the auth middleware returns 401.
Route::get('csrf-token', function (\Illuminate\Http\Request $request) {
    return response()
        ->json(['token' => csrf_token()])
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
})->middleware('web')->name('csrf.token');

// SEO: public sitemap + robots (registered before the admin catch-all).
Route::get('sitemap.xml', [\App\Http\Controllers\SitemapController::class, 'index'])->name('store.sitemap');
Route::get('robots.txt', [\App\Http\Controllers\SitemapController::class, 'robots'])->name('store.robots');

Route::group(['middleware' => ['web', 'auth:web', 'Is_Active', 'request.safety']], function () {

    // The Vue 2 admin SPA has been retired (its bundle no longer exists). Any
    // old admin URL — including "/" — now redirects into the Vue 3 app at /next,
    // whose router lands on /next/dashboard.
    Route::get('/{vue?}',
        function () {
            $installed = Storage::disk('public')->exists('installed');

            if ($installed === false) {
                return redirect('/setup');
            }

            return redirect('/next');
        })->where('vue', '^(?!api|setup|update|password|online_store|customer-display|quickbooks|portal|recruit|api-docs|next|csrf-token|login|logout).*$');

});

// Laravel 12 compatibility: define auth routes explicitly (laravel/ui optional)
Route::get('login', 'Auth\LoginController@showLoginForm')->name('login');
// 5 attempts per minute per IP — blocks credential-stuffing / brute force.
Route::post('login', 'Auth\LoginController@login')->middleware('throttle:5,1');
Route::post('logout', 'Auth\LoginController@logout')->name('logout');

// Password Reset Routes...
Route::get('password/reset', 'Auth\\ForgotPasswordController@showLinkRequestForm')->name('password.request');
// Throttle password-reset email requests to stop mailbox flooding.
Route::post('password/email', 'Auth\\ForgotPasswordController@sendResetLinkEmail')
    ->middleware('throttle:5,10')->name('password.email');
Route::get('password/reset/{token}', 'Auth\\ResetPasswordController@showResetForm')->name('password.reset');
Route::post('password/reset', 'Auth\\ResetPasswordController@reset')
    ->middleware('throttle:5,10')->name('password.update');

// ------------------------- -UPDATE ----------------------------------------\\

Route::group(['middleware' => ['web', 'auth:web', 'Is_Active']], function () {

    Route::get('/update', 'UpdateController@viewStep1');

    Route::get('/update/fix_auto_increment', 'UpdateController@fix_auto_increment');

    Route::get('/update/finish', function () {

        return view('update.finishedUpdate');
    });

    Route::post('/update/lastStep', [
        'as' => 'update_lastStep', 'uses' => 'UpdateController@lastStep',
    ]);

});

// -------------------- Public Invoice View (HMAC-signed, no login required) --------------------
Route::get('/invoice/{id}/{signature}', 'SalesController@publicInvoiceView')
    ->middleware(['web'])
    ->where('id', '[0-9]+')
    ->where('signature', '[a-f0-9]{32}');

// -------------------- Public Customer Display (token-guarded) --------------------
// Standalone public page that mounts its own Vue app. Does not affect existing SPA.
Route::get('/customer-display', function (HttpRequest $request) {
    $token = $request->query('token');
    if (! $token || $token !== cache('customer_display_token')) {
        abort(403, 'Unauthorized display access');
    }

    return view('customer_display');
})->middleware(['web']);
