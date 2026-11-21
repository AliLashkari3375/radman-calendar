# Radman Calendar 1.7.8

## Changes
- Lanmis connector: more generous network budgets on the front-end and dashboard (call/time limits and request timeouts raised) to avoid incomplete data on slow API responses.
- Image export: day-pattern extraction rewritten. Weekday names are now detected and normalized (e.g. "یکشنبه و سه‌شنبه"), with a safe fallback for free-text schedules.
- Image export: course rows are no longer merged by title; each course keeps its own row.
- Fixed author name typo in the plugin header.

## تغییرات (فارسی)
- اتصال لن‌میس: بودجه‌ی شبکه در فرانت و پیشخوان سخاوتمندانه‌تر شد (سقف تعداد تماس، زمان کل و timeout) تا با کندی API داده‌ی ناقص نمایش داده نشود.
- خروجی تصویر: استخراج روزهای برگزاری بازنویسی شد؛ نام روزهای هفته تشخیص و یکدست می‌شود (مثلاً «یکشنبه و سه‌شنبه») و برای متن‌های آزاد حالت جایگزین امن دارد.
- خروجی تصویر: ردیف دوره‌ها دیگر بر اساس عنوان ادغام نمی‌شود و هر دوره ردیف خودش را دارد.
- اصلاح غلط املایی نام نویسنده در هدر پلاگین.
