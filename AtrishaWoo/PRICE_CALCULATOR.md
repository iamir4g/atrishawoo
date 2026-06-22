# محاسبه قیمت محصولات (Price Calculator)

این ماژول بخشی از **AtrishaWoo** است و منطق پروژه `PriceCalculator` را برای محاسبه و به‌روزرسانی قیمت واریانت‌های عطر در WooCommerce فراهم می‌کند.

> **هشدار:** این ابزار قیمت‌های زنده واریانت‌ها را تغییر می‌دهد. قبل از import در production، روی staging تست کنید و از دیتابیس backup بگیرید.

## دسترسی

**WordPress Admin → AtrishaWoo → محاسبه قیمت**

یا مستقیم:

`admin.php?page=atrishawoo-price`

## قابلیت‌ها

| بخش | کاربرد |
| --- | --- |
| Dashboard | وضعیت صف، تعداد محصولات/واریانت‌ها، پیشرفت batch |
| Pricing Engine | ذخیره ورودی‌های پیش‌فرض قیمت + enqueue تک‌SKU |
| Batch Processor | شروع پردازش دسته‌ای (Action Scheduler / WP-Cron) |
| Logs | مشاهده و جستجوی لاگ |
| Settings | آپلود CSV و فرم fallback پردازش |

## فرمول قیمت (خلاصه)

برای هر واریانت با حجم و غلظت شناخته‌شده:

```text
essence_cost = volume × essence_percent × gram_price
fix_cost     = volume × (1 - essence_percent) × fixative_price
raw_cost     = essence_cost + fix_cost
production   = raw_cost × 3
base_cost    = production + bottle + packaging
final_price  = (base_cost + shipping) × (1 + tax_percent/100)
price        = round(final_price)
```

غلظت‌های پشتیبانی‌شده: `EX`, `EDP`, `EDT`, `EDC` (و معادل فارسی).  
حجم‌های پشتیبانی‌شده برای gram price: `10`, `30`, `50`, `100`.

## CSV Import

هدر دقیق (ترتیب مهم است):

```csv
BaseSKU,GramPrice_100,GramPrice_50,GramPrice_30,GramPrice_10,FixativePrice,BottlePrice,PackagingPrice,ShippingCost,TaxPercent
```

- `BaseSKU` باید SKU یک **واریانت** باشد (نه محصول ساده/والد).
- import از تب Settings در UI انجام می‌شود.

## پیش‌نیازهای محصول

- والد: `variable product`
- فرزند: `variation`
- attribute غلظت (کلید شامل `غلظت` یا مقدار شناخته‌شده)
- attribute حجم (کلید شامل `حجم` یا عدد قابل parse)

## دیتابیس

جدول صف: `{prefix}perfume_price_jobs`

وضعیت‌ها: `pending` → `processing` → `done` / `failed`

## لاگ

مسیر:

`wp-content/uploads/mgmp-logs/perfume-price-log.txt`

## UI

- کل پنل AtrishaWoo از **Material UI (MUI v5)** برای navigation استفاده می‌کند.
- تب محاسبه قیمت یک اپ React/MUI کامل با sidebar داخلی دارد.
- React/MUI از فایل‌های داخلی `assets/vendor/` لود می‌شوند (بدون وابستگی به CDN).

## ساختار فایل در AtrishaWoo

```text
AtrishaWoo/
├── includes/
│   ├── class-atrishawoo-price-calculator.php   # bootstrap ماژول
│   └── price-calculator/                       # کلاس‌های backend
├── assets/
│   ├── admin-shell.js                          # MUI tab bar
│   ├── admin-mui.css
│   └── price-calculator/
│       ├── admin.js                            # React/MUI app
│       └── admin.css
└── PRICE_CALCULATOR.md
```

## عیب‌یابی سریع

| مشکل | راه‌حل |
| --- | --- |
| UI لود نمی‌شود | console مرورگر + دسترسی CDN + وجود `assets/price-calculator/admin.js` |
| SKU پیدا نمی‌شود | SKU باید متعلق به variation باشد |
| قیمت بعضی واریانت‌ها عوض نمی‌شود | attribute حجم/غلظت parse نشده یا حجم خارج از 10/30/50/100 |
| job گیر کرده در processing | worker متوقف شده؛ بررسی Action Scheduler / WP-Cron |

## مستندات کامل

مستندات production-grade اصلی در repository پروژه `PriceCalculator` (`README.md`) موجود است.
