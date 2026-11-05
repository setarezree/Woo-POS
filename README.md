# Universal WooCommerce POS & Store Manager

نویسنده: **ستاره زارعی** — نسخهٔ **0.4.0**، صندوق فروش آنلاین با رابط فارسی و فاکتور قابل چاپ.

## نصب

نیازمندی‌ها: PHP 8.1 یا بالاتر، WordPress 6.7 یا بالاتر، WooCommerce فعال نسخهٔ 9.0 یا بالاتر.

[دانلود مستقیم بستهٔ نصب نسخهٔ فعلی](https://github.com/setarezree/Woo-POS/raw/refs/heads/main/dist/universal-woocommerce-pos.zip)

ابتدا از سایت آزمایشی استفاده کنید. فایل `dist/universal-woocommerce-pos.zip` را از «افزونه‌ها ← افزودن ← بارگذاری افزونه» نصب و فعال کنید. هفت جدول با پیشوند همان سایت ایجاد می‌شوند. ارتقا از نسخهٔ 0.1.0 افزایشی است و داده‌های موجود را پاک نمی‌کند. جدول‌های درگیر باید InnoDB باشند؛ افزونه موتور جدول‌های سایت را خودکار تغییر نمی‌دهد. فعال‌سازی شبکه‌ای پشتیبانی نمی‌شود؛ در چندسایتی روی هر سایت جداگانه فعال کنید. در صورت نبود WooCommerce، هستهٔ عملیاتی و API بارگذاری نمی‌شوند و مدیر پیام وابستگی را می‌بیند.

حذف یا غیرفعال‌سازی افزونه، داده‌های مالی و نقش‌ها را پاک نمی‌کند. حذف دائمی داده‌ها به سیاست نگهداری و ابزار جداگانه نیاز دارد.

## محدودهٔ پیاده‌سازی

- بارگذار کلاس‌ها با namespace `StarZarei\UniversalPOS`، چرخهٔ فعال‌سازی و ارتقا، بررسی وابستگی و رابط `Module`.
- هفت جدول اختصاصی نسخه‌دار با `dbDelta`، پیشوند پویا، InnoDB، ایندکس‌ها و کلیدهای یکتای درخواست/رویداد/پرداخت.
- نقش `upos_cashier` و مجوزهای مستقل؛ مدیر کل تمام مجوزهای افزونه را دارد.
- API خواندن تنظیمات واقعی فروشگاه، محصولات منتشرشده، تنوع‌ها و خلاصهٔ سفارش‌ها.
- دریافت قیمت، موجودی، SKU، دسته‌بندی، ویژگی‌ها و شناسهٔ تصاویر از WooCommerce؛ بدون جدول موازی یا کش مستقل.
- متادیتای کانال فروش و شناسهٔ صندوق‌دار از طریق CRUD سفارش؛ اعلام و آزمایش سازگاری HPOS.
- رشته‌های قابل ترجمه، فایل POT و ترجمهٔ فارسی پیام‌های فعلی.
- ایجاد سفارش واقعی در انتظار پرداخت با قیمت و مالیات WooCommerce، مشتری مهمان یا کاربر موجود با مجوز مناسب.
- رزرو پنج‌دقیقه‌ای موجودی WooCommerce، لغو سفارش پرداخت‌نشده و زمان‌بندی انقضای آن با Action Scheduler.
- تأیید دستی دریافت نقدی، کارت‌خوان و انتقال بانکی، از جمله پرداخت ترکیبی دقیق؛ نهایی‌سازی با `payment_complete()`.
- جلوگیری از ثبت دوباره درخواست و پرداخت، دفتر دریافت‌های مالی، ثبت گردش موجودی فروش POS و ثبت عملیات حساس در تراکنش دیتابیس.
- رابط مستقل از قالب با ورود از منوی «صندوق فروش»، جست‌وجوی نام/SKU و اسکن بارکد یا SKU، انتخاب تنوع، ویرایش سبد و ثبت مشتری مهمان.
- نمایش نتیجهٔ واقعی فروش، بازیابی پاسخ نامشخص بدون سفارش تکراری، فروش‌های اخیر همان صندوق‌دار و چاپ مجدد فاکتور ۵۸/۸۰ میلی‌متری.
- کوپن واقعی WooCommerce یا تخفیف مبلغی/درصدی دارای مجوز و دلیل؛ بارکد یکتای محصول و تنوع؛ ذخیره و ادامهٔ سبد هفت‌روزه روی سرور.
- رابط واکنش‌گرا، دسترسی با کیبورد، نمایش RTL فارسی پیش‌فرض و LTR انگلیسی، بدون ذخیرهٔ سبد یا مشتری و پرداخت در مرورگر.

**در این نسخه هنوز پیاده‌سازی نشده‌اند:** ایجاد/ویرایش محصول از پنل افزونه، انتخاب مشتری دارای حساب از رابط صندوق، مرجوعی، گزارش‌ها و CRM کامل، ثبت گردش موجودی تمام تغییرات آنلاین/مدیریتی و اتصال سخت‌افزاری کارت‌خوان. ثبت فروش و پرداخت از رابط صندوق و API قابل استفاده است. ثبت پرداخت، تأیید صندوق‌دار دربارهٔ وجه واقعاً دریافت‌شده است؛ این افزونه درگاه یا دستگاه کارت‌خوان را فراخوانی نمی‌کند.

شرح کامل گردش فروش، نمونهٔ درخواست‌ها، محدودیت‌ها و رفتار تکرار درخواست در [راهنمای API فروش](docs/pos-api.md) آمده است.

راهنمای رابط و چاپ در [راهنمای صندوق](docs/cashier-guide.md) و ترتیب توسعهٔ تأییدشده در [نقشهٔ راه](docs/roadmap.md) است.

برای ایجاد/ویرایش فعلی محصولات از پیشخوان یا REST API استاندارد WooCommerce استفاده کنید؛ تغییرات در API این افزونه منعکس می‌شوند. هسته مستقیماً SQL محصولات یا سفارش‌ها را تغییر نمی‌دهد. هیچ قابلیت آفلاین یا شیفتی وجود ندارد.

## ساخت صندوق‌دار و مجوزها

از «کاربران ← افزودن» کاربر بسازید و نقش **POS Cashier** را انتخاب کنید. مجوزهای پیش‌فرض فقط `read`، `upos_access_pos`، `upos_create_sales` و `upos_view_inventory` هستند. پس از ورود، منوی «صندوق فروش» رابط مستقل را باز می‌کند. صندوق‌دار می‌تواند فروش‌های خودش را ایجاد، بررسی و پرداخت یا پیش از دریافت وجه لغو کند؛ به فروش صندوق‌دار دیگر دسترسی ندارد. صندوق‌دار مجوز مدیریت WooCommerce، ویرایش محصول، خواندن سایر سفارش‌ها یا تنظیمات را ندارد. تخصیص مشتری دارای حساب از API به مجوز `upos_view_customers` نیز نیاز دارد؛ رابط فعلی فقط اطلاعات مشتری مهمان را دریافت می‌کند.

فهرست تمام مجوزها در `includes/Users/Capabilities.php` قرار دارد. تغییر مجوز از API استاندارد نقش‌های WordPress انجام می‌شود؛ صفحهٔ تنظیم مجوزها هنوز ساخته نشده است. هیچ مجوز استاندارد مدیریتی به صندوق‌دار اضافه نکنید مگر اینکه واقعاً به آن نیاز دارد. ارتقای افزونه نقش موجود را به تنظیمات اولیه بازنمی‌گرداند.

## API

پیشوند: `/wp-json/universal-pos/v1`؛ مسیرهای زیر خواندنی‌اند. مسیرهای ثبت فروش `POST` در راهنمای API فروش مستند شده‌اند.

| مسیر | کاربرد | مجوز |
| --- | --- | --- |
| `/store` | ارز، مالیات، فرمت قیمت، نسخه‌ها و وضعیت واقعی HPOS | `upos_access_pos` |
| `/products` | محصولات منتشرشده با صفحه‌بندی | `upos_view_inventory` |
| `/products/{id}` | جزئیات محصول یا تنوع منتشرشده | `upos_view_inventory` |
| `/orders/{id}` | خلاصهٔ سفارش، اقلام، کانال و صندوق‌دار | `upos_view_orders` و مجوز استاندارد `edit_shop_order` برای همان سفارش |

پارامترهای محصول: `page`، `per_page` (۱ تا ۱۰۰)، `search` (نام)، `sku` و `parent` (تنوع‌های محصول والد). مسیر `/products/scan?code=...` بارکد اختصاصی یا SKU دقیق را می‌یابد؛ تطبیق مبهم رد می‌شود. تعداد نتایج در `X-WP-Total` و تعداد صفحات در `X-WP-TotalPages` برمی‌گردد. پاسخ‌ها `Cache-Control: no-store, private` دارند. مقدارهای پول رشته‌اند و ارز ثابت نیست.

برای درخواست مرورگری از نشست واردشدهٔ WordPress و هدر `X-WP-Nonce` با nonce ساخته‌شده از `wp_create_nonce('wp_rest')` استفاده کنید. برای اتصال سرور به سرور، Application Password استاندارد WordPress روی HTTPS پشتیبانی می‌شود. اطلاعات ورود یا API key را در کد سمت کاربر قرار ندهید. احراز هویت و اعتبارسنجی nonce به سازوکار استاندارد REST وردپرس سپرده شده است.

## معماری و گردش داده

```text
universal-woocommerce-pos.php
includes/
  Core/        Plugin, Installer, Module
  Database/    Schema, AtomicOperation
  Users/       Capabilities
  Products/    ProductRepository, Barcode
  Orders/      SalesChannel
  API/         StoreController, SalesController
  POS/         SaleService, SaleInput, SaleException, Expiry, App, Strings, Discounts, ParkedCarts
  Payments/    Money, ManualPayments, DeferredEmails
languages/     POT, fa_IR.po, fa_IR.mo
tests/         integration.php, dependency.php, sales.php, concurrency.php
               interface.php, pos-extras.php, browser.cjs, browser-extras.cjs, browser-recovery.cjs, browser-fixture.php
templates/     pos/app.php, receipts/thermal.php, admin/barcode-settings.php
assets/        css/pos.css, css/receipt.css, js/pos.js, js/receipt.js
tools/         build.py, translations.php
docs/          pos-api.md, test-report.md
uninstall.php
readme.txt
README.md
LICENSE
dist/          universal-woocommerce-pos.zip (generated)
```

```text
REST → WordPress authentication → capability check → WooCommerce CRUD → response
Plugin activation/update → Installer → Schema/dbDelta → {prefix}upos_* tables
WooCommerce order save → SalesChannel → CRUD metadata → active WooCommerce order store
Prepare sale → idempotency lock → pending WC order → native WC stock reservation
Confirm receipt → order lock → transaction → ledger + payment_complete + stock movement → commit
```

ماژول‌ها از `Core\Module` پیروی می‌کنند و از فیلتر `upos_modules` ثبت می‌شوند؛ اکشن `upos_loaded` پایان راه‌اندازی را اعلام می‌کند. `ProductRepository` از `wc_get_products` و `wc_get_product` استفاده می‌کند. `SaleService` گردش فروش و `ManualPayments` ثبت دریافت‌ها را مدیریت می‌کند. `SalesChannel::mark_pos()` فقط متادیتای کانال و صندوق‌دار را روی شیء سفارش قرار می‌دهد. سفارش‌های قدیمی بدون متادیتا، هنگام خواندن `online` محسوب می‌شوند؛ سفارش جدید هنگام ذخیره مقدار پیش‌فرض می‌گیرد.

## دیتابیس

| جدول | مسئولیت |
| --- | --- |
| `{prefix}upos_cash_registers` | تعریف صندوق مستقل از شیفت |
| `{prefix}upos_payment_lines` | اجزای پرداخت با کلید یکتای idempotency |
| `{prefix}upos_financial_transactions` | دفتر تراکنش با ارجاع منطقی سفارش، پرداخت و صندوق |
| `{prefix}upos_stock_movements` | تاریخچهٔ تغییر موجودی با event key یکتا |
| `{prefix}upos_audit_logs` | ساختار ثبت عملیات و عامل آن |
| `{prefix}upos_parked_carts` | سبد موقت هفت‌روزهٔ همان صندوق‌دار، بدون قیمت ذخیره‌شده یا رزرو موجودی |
| `{prefix}upos_sale_requests` | نگاشت کلید یکتای درخواست به شناسهٔ سفارش و هش ورودی؛ بدون کپی محصول یا سفارش |

مقادیر پول و تعداد `DECIMAL(26,8)` هستند. زمان‌های ثبت UTC و از `current_time('mysql', true)` هستند. شناسه‌های سفارش و محصول ارجاع منطقی‌اند و foreign key فیزیکی به جدول‌های داخلی WooCommerce ندارند. نسخهٔ ساختار در `upos_db_version` ذخیره می‌شود و فقط پس از موفقیت همهٔ مراحل ارتقا تغییر می‌کند. نسخهٔ دوم رجیستری درخواست فروش و نسخهٔ سوم سبدهای موقت سروری را اضافه می‌کنند؛ محصول و سفارش همچنان فقط در WooCommerce نگهداری می‌شوند.

## توسعه و تست

هر وظیفهٔ ابری از قبل محیط ایزوله دارد؛ از checkout موجود استفاده کنید و بدون درخواست صریح worktree نسازید.

روی یک WordPress کاملاً موقت، WooCommerce را فعال کنید، افزونه را با مسیر پوشهٔ `universal-woocommerce-pos` نصب کنید و مدیر آزمایشی با نام `upos_test_admin` بسازید. در `wp-config.php` همان سایت موقت، `WP_ENVIRONMENT_TYPE` را `local` و `UPOS_INTEGRATION_TESTS` را `true` قرار دهید. آزمون داده‌های موقت ایجاد می‌کند و تنظیم HPOS را در حین اجرا تغییر می‌دهد؛ روی سایت اصلی اجرا نشود.

```sh
php tests/integration.php /absolute/path/to/disposable/wordpress
php tests/dependency.php /absolute/path/to/disposable/wordpress
php tests/sales.php /absolute/path/to/disposable/wordpress
php tests/concurrency.php /absolute/path/to/disposable/wordpress
php tests/interface.php /absolute/path/to/disposable/wordpress
php tests/pos-extras.php /absolute/path/to/disposable/wordpress
php tools/translations.php /absolute/path/to/wordpress
python3 tools/build.py
```

آزمون‌ها به WordPress، WooCommerce و دیتابیس واقعی متصل می‌شوند و داده‌های آزمایشی را در پایان پاک می‌کنند. گزارش اجرای انجام‌شده در `docs/test-report.md` است. ZIP خروجی با یک پوشهٔ ریشهٔ استاندارد در `dist/` ساخته می‌شود.

آزمون مرورگر به Node.js، Playwright و Chromium نیاز دارد؛ افزونه در اجرا به Node یا npm وابسته نیست. دستور ساخت دادهٔ موقت و اجرای تست در راهنمای صندوق آمده است. فایل fixture شامل نشست آزمایشی است و باید خارج از checkout نگهداری و پس از آزمون پاک شود.

برای انقضای خودکار سفارش‌های پرداخت‌نشده، WP-Cron یا runner استاندارد Action Scheduler باید فعال باشد. حتی در تأخیر runner، پرداخت پس از انقضای پنج دقیقه رد می‌شود و رزرو موجودی WooCommerce زمان انقضای خودش را دارد؛ اجرای job وضعیت سفارش را نیز لغوشده می‌کند.

## تکمیل امکانات POS در 0.4.0

کوپن استاندارد WooCommerce با محدودیت‌های مشتری/کالا/مصرف، یا تخفیف دستی مبلغی/درصدی با مجوز `upos_apply_discounts` و دلیل اجباری قابل اعمال است؛ ترکیب این دو پذیرفته نمی‌شود. اعتبار کوپن و مجوز تخفیف هنگام ثبت وجه دوباره بررسی می‌شوند. بارکد اختصاصی در فرم استاندارد محصول و هر تنوع ثبت می‌شود؛ کلید متای قابل تنظیم، پیش‌فرض `_upos_barcode` است و کلیدهای داخلی WooCommerce پذیرفته نمی‌شوند.

سبد را می‌توان پیش از تکمیل اطلاعات مشتری برای هفت روز روی سرور ذخیره، بازیابی، ویرایش یا حذف کرد. این کار سفارش یا رزرو موجودی ایجاد نمی‌کند؛ بازیابی قیمت روز را می‌گیرد و تنها آماده‌سازی فروش موجودی را رزرو می‌کند. پس از تبدیل به سفارش، همان سبد دوباره مصرف نمی‌شود. جزئیات در راهنمای صندوق و API آمده‌اند. مرحلهٔ بعد طبق ترتیب تأییدشده، انبار، سفارش‌ها و مرجوعی است.
