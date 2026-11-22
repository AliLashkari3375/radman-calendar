# Radman Calendar

**English** | [فارسی](#فارسی)

A complete educational calendar system for WordPress: Lanmis API integration, calendar/course shortcodes, and a full admin panel.

## ⚠️ License

This repository is published **for source viewing (portfolio) only** and is **All Rights Reserved**.
Reading the code is free, but copying, modifying, distributing, selling, or using this plugin on any site
without the author's written permission is **not allowed**. See [`LICENSE`](./LICENSE) for the full terms.

## Features

- Lanmis API integration to fetch classes and course data
- Calendar and course-list shortcodes with multiple display templates
- Native Jalali (Persian) calendar, no external dependencies
- Admin panel: central settings, visual shortcode builder, manual data entry, license system
- Image export of tables (html2canvas), with smart weekday detection and one row per course
- Department priority and English department names matched reliably (Arabic/Persian letter variants, half-spaces and the optional "دپارتمان" prefix are normalized)
- Manual class priority with Persian-digit support, inherited by course title across terms in multi-month date ranges
- Automatic updates via GitHub Releases

**Current version: 1.8.1** — see the [Releases](../../releases) page and the `CHANGELOG-*.md` files for the full history.

## Installation (authorized users only)

1. Download the latest version from the [Releases](../../releases) page.
2. Upload the zip to `wp-content/plugins/` (or via Plugins → Add New → Upload) and activate it.
3. In the plugin settings, enter your Lanmis API token and license key.

## Updates

Automatic updates work out of the box: the plugin already points to this repository, so no configuration is needed.
When a newer release is published here, it appears in WordPress under Dashboard → Updates and Plugins.

Release rules (for the author):
- Every new version gets its own new release (tag such as `v1.8.2`); never edit older releases.
- Attach the plugin zip as an asset named exactly `radman-calendar.zip` (with the `radman-calendar/` folder inside it).
- Only the newest release is marked **Latest**.

Customers see a new version within a few hours (the update check is cached for up to 6 hours), or right after the cache expires when they press "Check again" on Dashboard → Updates.

## Contact for licensing

- Ali Lashkari
- Email: alilashkari020@gmail.com
- Website: https://alilashkari.ir/

---

<div dir="rtl">

<a name="فارسی"></a>

# رادمان کلندر (Radman Calendar)

[English](#radman-calendar) | **فارسی**

سیستم جامع تقویم آموزشی برای وردپرس — اتصال به API لن‌میس، شرت‌کدهای تقویم/دوره، و پنل مدیریت کامل.

## ⚠️ لایسنس

این ریپو فقط برای **نمایش کد (پورتفولیو)** منتشر شده و **All Rights Reserved** است.
مشاهده‌ی کد آزاد است، اما کپی، ویرایش، توزیع، فروش یا استفاده از این پلاگین روی
هر سایتی بدون اجازه‌ی کتبی نویسنده **مجاز نیست**. برای جزئیات کامل فایل
[`LICENSE`](./LICENSE) را ببین.

## ویژگی‌ها

- اتصال به API لن‌میس برای دریافت اطلاعات کلاس‌ها و دوره‌ها
- شرت‌کدهای تقویم و لیست دوره با چندین قالب نمایش
- تقویم شمسی (جلالی) native، بدون وابستگی خارجی
- پنل مدیریت: تنظیمات مرکزی، شرت‌کد ساز بصری، داده‌ی دستی، سیستم لایسنس
- خروجی تصویر از جدول‌ها (html2canvas)، با تشخیص هوشمند روزهای هفته و ردیف جداگانه برای هر دوره
- تطبیق مطمئن اولویت دپارتمان‌ها و نام انگلیسی دپارتمان‌ها (یکسان‌سازی ی/ک عربی و فارسی، نیم‌فاصله و پیشوند اختیاری «دپارتمان»)
- اولویت دستی کلاس‌ها با پشتیبانی از ارقام فارسی که در بازه‌های چندماهه بین ترم‌ها بر اساس عنوان دوره به ارث می‌رسد
- بروزرسانی خودکار از طریق GitHub Releases

**نسخه‌ی فعلی: ۱٫۸٫۱** — تاریخچه‌ی کامل را در بخش [Releases](../../releases) و فایل‌های `CHANGELOG-*.md` ببین.

## نصب (فقط برای کاربران مجاز)

۱. آخرین نسخه را از بخش [Releases](../../releases) دانلود کن.
۲. زیپ را در `wp-content/plugins/` آپلود (یا از افزونه‌ها ← افزودن ← بارگذاری) و فعال کن.
۳. از منوی تنظیمات پلاگین، توکن API لن‌میس و کلید لایسنس را وارد کن.

## بروزرسانی

آپدیت خودکار بدون هیچ تنظیمی کار می‌کند: پلاگین از قبل به همین ریپو وصل است.
وقتی نسخه‌ی جدیدتری اینجا منتشر شود، در پیشخوان وردپرس بخش «به‌روزرسانی‌ها» و «افزونه‌ها» نمایش داده می‌شود.

قوانین Release (برای نویسنده):
- هر نسخه‌ی جدید Release جدید خودش را با تگ مخصوص (مثلاً `v1.8.2`) دارد؛ Releaseهای قبلی ویرایش نمی‌شوند.
- زیپ پلاگین (با پوشه‌ی `radman-calendar/` داخلش) دقیقاً با نام `radman-calendar.zip` به‌عنوان Asset پیوست می‌شود.
- فقط جدیدترین Release برچسب **Latest** دارد.

مشتری‌ها نسخه‌ی جدید را ظرف چند ساعت می‌بینند (بررسی بروزرسانی تا ۶ ساعت کش می‌شود) یا بعد از پایان کش با زدن «بررسی دوباره» در پیشخوان ← به‌روزرسانی‌ها.

## تماس برای مجوز استفاده

- علی لشکری
- ایمیل: alilashkari020@gmail.com
- وبسایت: https://alilashkari.ir/

</div>
