# API انبار، سفارش و مرجوعی — 0.5.0

پیشوند مسیرها `/wp-json/universal-pos/v1/management` است. احراز هویت WordPress و nonce معتبر کوکی، یا Application Password استاندارد روی HTTPS لازم است. مجوزهای افزونه و بومی WooCommerce مطابق [راهنمای عملیات](management-guide.md) در سرور بررسی می‌شوند. پاسخ‌ها private/no-store هستند. فرم‌ها دادهٔ قیمت/موجودی/مالیات را مرجع نمی‌دانند.

## مسیرها

| روش | مسیر | کاربرد |
| --- | --- | --- |
| GET | `/products` | کالاهای واقعی شامل draft/private مجاز؛ `search`، `sku`، `stock_status`، `parent`، `page`، `per_page` تا ۱۰۰ |
| GET | `/products/{id}` | جزئیات جاری، `physical_stock`، `stock_managed_by_id`، `low_stock_amount` و `low_stock` |
| POST | `/products/{id}/stock` | تغییر موجودی صحیح، با `Idempotency-Key` |
| GET | `/stock-history` | ۵۰ گردش در صفحه؛ فیلتر `product_id` (شامل variation)، `order_id`، `type`، `from`، `to`، `page`؛ تاریخ UTC با قالب YYYY-MM-DD |
| GET | `/orders` | فهرست native؛ `search`، `status` بدون wc-، `channel=pos/online`، `from`، `to`، `page`، `per_page` تا ۱۰۰ |
| GET | `/orders/{id}` | خلاصه، اقلام و تعداد مرجوع‌شده، مانده، روش و دریافت‌های POS، یادداشت‌های داخلی و refundهای native |
| POST | `/orders/{id}/notes` | `{"note":"متن داخلی"}` با کلید یکتا |
| POST | `/orders/{id}/status` | `{"status":"completed","expected_status":"processing"}` با کلید یکتا |
| POST | `/orders/{id}/refund-quote` | محاسبهٔ مرجوعی از دادهٔ واقعی، بدون نوشتن refund یا تغییر موجودی |
| POST | `/orders/{id}/refunds` | ثبت refund واقعی و وجهِ قبلاً برگشت‌داده‌شده، با کلید یکتا |
| GET | `/operations?kind=...&key=...` | بازیابی نتیجهٔ همان کاربر بعد از پایان درخواست در جریان |
| POST | `/operations/abandon` | `{"kind":"...","key":"..."}`؛ بازیابی نتیجه یا ساخت نشانگر انصراف پایدار |

فیلتر زمان سفارش‌ها تابع timezone/query بومی WooCommerce است؛ دفتر موجودی UTC است. فهرست‌ها محصول/سفارش موازی یا snapshot پایدار ندارند. جست‌وجوی سفارش از `wc_order_search` و channel/date/status از query بومی در هر دو حالت HPOS می‌آید.

## تغییر موجودی

```json
{
  "type": "receipt",
  "quantity": 5,
  "expected_stock": 20,
  "reason": "ورود واقعی کالا",
  "low_stock_amount": 3
}
```

انواع `receipt`، `damage`، `waste`، `adjustment` و `stocktake` هستند. سه نوع نخست، مقدار ورود/خروج و دو نوع آخر، مقدار نهایی‌اند. `quantity` و `expected_stock` عدد صحیح JSON هستند؛ مقدار نهایی منفی یا پایین‌تر از رزرو native رد می‌شود. `low_stock_amount` صحیحِ غیرمنفی و اختیاری است؛ دلیل ۱ تا ۱۰۰۰ نویسه الزامی است. بازهٔ quantity/حداقل از صفر تا یک میلیارد است. مغایرت موجودیِ خوانده‌شده ۴۰۹ می‌دهد. تنوع با موجودی والد همان stock owner واقعی را تغییر می‌دهد؛ مجوز هر دو بررسی می‌شود.

برای stock row lock از عملیات صفرِ API بومی WooCommerce درون تراکنش استفاده می‌شود و سپس مقدار/رزرو دوباره خوانده می‌شود. تغییر واقعی هم فقط با `wc_update_product_stock` انجام می‌شود. دفتر مشاهده‌گر استاندارد، انتقال واقعی را ثبت می‌کند؛ no-op مقدار جعلی در گردش نمی‌سازد.

## محاسبه و ثبت مرجوعی

بدنهٔ quote:

```json
{
  "mode": "partial",
  "items": [{"item_id": 456, "quantity": 1, "condition": "saleable"}],
  "reason": "کالای سالم تحویل گرفته شد"
}
```

`item_id` شناسهٔ ردیف همان سفارش است، نه محصول. تا ۱۰۰ ردیف یکتا و تعداد صحیح ۱ تا ۱۰۰۰ مجاز است. condition یکی از `saleable`، `damaged` یا `inspection` است. `mode=full` به تعداد کامل باقی‌مانده و وضعیت صریح تمام اقلام محصول نیاز دارد و باقیماندهٔ shipping/fee/مالیات را نیز محاسبه می‌کند. partial فقط بر حسب تعداد ردیف‌های محصول است. دلیل الزامی و حداکثر ۱۰۰۰ نویسه است.

quote شامل `amount` به‌صورت رشتهٔ اعشاری، `currency`، `quote_hash`، تخصیص `lines` و `conditions` است. تخصیص از مبلغ پس از تخفیف و هر نرخ مالیات همان ردیف انجام می‌شود؛ در آخرین مرجوعی، تمام باقیماندهٔ گردکردن گرفته می‌شود. سقف تعداد و مبلغ بر اساس همهٔ refundهای native، حتی ایجادشده از ابزارهای دیگر، بررسی می‌شود.

برای ثبت، همان ورودی و فیلدهای زیر را بفرستید:

```json
{
  "mode": "partial",
  "items": [{"item_id": 456, "quantity": 1, "condition": "saleable"}],
  "reason": "کالای سالم تحویل گرفته شد",
  "quote_hash": "هش ۶۴ نویسه‌ای پاسخ سرور",
  "expected_amount": "100.00",
  "received": true,
  "method": "cash",
  "reference": ""
}
```

`received=true` در این endpoint یعنی **مدیر تأیید می‌کند وجه محاسبه‌شده قبلاً به مشتری برگشت داده شده است**. افزونه بانک یا درگاه را فراخوانی نمی‌کند. روش‌ها همان روش‌های دستی فعال POS هستند؛ غیرنقدی شمارهٔ پیگیری می‌خواهد. `refund_payment=false` وضعیت واقعی API native را حفظ می‌کند و به معنی انتقال بانکی خودکار نیست.

سفارش باید تاریخ پرداخت و وضعیت processing/completed/refunded قابل بررسی داشته باشد. سفارش پرداخت‌نشده، تغییر ارز/دقت ثبت‌شده و مبلغ غیرقابل بیان با دقت فعلی رد می‌شوند. `quote_hash` با سفارش، اقلام، وضعیت، refundهای قبلی، مبلغ و دلیل دوباره محاسبه می‌شود؛ تغییر quote یا مانده ۴۰۹ است.

در یک تراکنش، `WC_Order_Refund` با `wc_create_refund` ساخته، فقط کالای قابل فروش با `wc_restock_refunded_items` برگردانده، شرایط روی خود refund ذخیره و خروجی مثبت مالی `sale_refund` با `refund_id` ثبت می‌شود. دریافت اصلی تغییر نمی‌کند. مبلغ صفر، ردیف مالی ساختگی ایجاد نمی‌کند. نتیجهٔ موفق `{refund_id, amount, order}` از رکوردهای واقعی است.

## تکرار و نتیجهٔ نامشخص

قواعد کلید همان POS است: ۸ تا ۱۲۸ حرف/رقم لاتین یا `. _ : -`. هر نوشتن مدیریتی یک کلید تازه دارد؛ retry همان کلید و بدنه را نگه می‌دارد. نوع عملیات برای lookup عبارت است از `stock:{product_id}`، `refund:{order_id}`، `order:{order_id}:note` یا `order:{order_id}:status`.

رجیستری به actor و kind محدود است و فقط payload hash و شناسه‌های نتیجه دارد. lookup ابتدا همان قفل عملیات را می‌گیرد؛ نتیجهٔ کامل `{found:true,...result_ids}`، نتیجهٔ هنوز ثبت‌نشده `{found:false}` و انصراف `{found:true,abandoned:true}` است. abandon یک عملیات ثبت‌شده را لغو نمی‌کند؛ در نبود نتیجه، نشانهٔ انصراف جلوی درخواست دیررس با همان کلید را می‌گیرد. `POST` با بدنهٔ متفاوت یا کلیدِ منصرف‌شده ۴۰۹ است.

در ۵۰۳ یا قطع پاسخ، ابتدا lookup انجام دهید؛ تکرار دریافت/برگشت وجه یا ساخت کلید جدید برای همان قصد انجام نشود. بعد از reload که بدنهٔ RAM موجود نیست، فقط lookup یا abandon از روی کلید URL ممکن است. انصراف امن، سپس قصد تازه با کلید تازه می‌خواهد. دفتر/بخش refund سفارش هم نتیجهٔ واقعی را نشان می‌دهد.

## خروجی و فاکتور

CSV از `admin-post.php?action=upos_stock_export`، با nonce اکشن `upos_stock_export` و همان مجوز انبار صادر می‌شود. فیلترهای تاریخ/محصول/سفارش/نوع پذیرفته می‌شوند و سقف ۵۰۰۰ ردیف است. متن شبیه فرمول خنثی، عدد واقعی حفظ و فایل UTF-8/BOM است.

فاکتور مدیریتی از `admin-post.php?action=upos_order_receipt&order_id=...`، nonce اکشن `upos_order_receipt`، مجوز ویرایش همان سفارش و تاریخ پرداخت محافظت می‌شود. `paper=58/80` فقط عرض نمایش/چاپ را تعیین می‌کند. refund از مبلغ اولیه جداست و لینک عمومی فاکتور ساخته نمی‌شود.

درون تراکنش، خطای SQL حتی اگر در کد native نادیده گرفته شود پیش از دستور بعدی بررسی می‌شود؛ commit/rollback یا DDL تودرتو مجاز نیست. تعارض قفل ۴۰۹ و سایر خطاها ۵۰۳ می‌دهند؛ درخواست قبلی را ابتدا با همان کلید بررسی کنید.
