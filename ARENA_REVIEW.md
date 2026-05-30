# CPTT – Review Notes

این فایل برای ادامه توسعه داخل Arena ساخته شده و فقط خلاصه‌ای از ساختار فعلی پلاگین است.

## Workspace
- مسیر پروژه: `ccptt/`
- مخزن از گیت‌هاب کلون شده و آماده توسعه است.

## ساختار کلی
- `client-project-tracker.php`
  - فایل اصلی پلاگین
  - لود کلاس‌ها، ثبت اکتیویشن/دی‌اکتیویشن، بوت‌استرپ singletonها

- `includes/class-cptt-core.php`
  - ثبت CPTها:
    - `cptt_project`
    - `cptt_template`
    - `cptt_checklist_tpl`
  - ثبت role کارشناس (`cptt_expert`)
  - ثبت metaهای پروژه/تمپلیت
  - محدودسازی دسترسی کارشناسان به پروژه‌های خودشان
  - توابع تاریخ جلالی/میلادی

- `includes/class-cptt-admin.php`
  - متاباکس‌های ادمین برای پروژه، تمپلیت، چک‌لیست، یادداشت‌ها، حسابداری
  - ویرایش Stepها، Checklistها، User Taskها
  - داشبورد پروژه‌ها
  - صفحه حساب و کتاب
  - ذخیره و نرمال‌سازی داده‌ها
  - AJAX برای تمپلیت مراحل، تمپلیت چک‌لیست، quick pay

- `includes/class-cptt-frontend.php`
  - شورتکد `[cptt_my_projects]`
  - نمایش پروژه‌های کاربر در فرانت
  - Stepper + Modal
  - AJAX تکمیل تسک مشتری با متن/لینک/فایل
  - Endpoint ووکامرس در My Account: `cptt-projects`

- `includes/class-cptt-report.php`
  - تولید گزارش نهایی پروژه بعد از تکمیل کامل
  - نمایش مستقل HTML برای چاپ/PDF
  - کنترل دسترسی مشتری/کارشناس/ادمین

- `includes/class-cptt-settings.php`
  - تنظیمات برندینگ گزارش PDF
  - لوگو، امضا، مهر، رنگ اصلی، نام برند، متن فوتر

- `includes/class-cptt-sms.php`
  - تنظیمات SMS بر پایه webhook
  - کرون ساعتی برای یادآوری‌ها و deadline
  - پیامک تکمیل پروژه
  - پیامک یادآوری تسک مشتری
  - هشدار اتمام مهلت پروژه و مرحله

- `includes/class-cptt-woocommerce.php`
  - تب اختصاصی در محصول ووکامرس
  - تعریف پروژه/پروژه‌های خودکار برای هر محصول
  - ساخت پروژه بعد از سفارش paid/processing/completed
  - اتصال مشتری، محصول، دسته‌بندی و تمپلیت مراحل

## Assetها
- `assets/js/admin.js`
  - مدیریت UI ادمین
  - افزودن/حذف/مرتب‌سازی stepها
  - چک‌لیست و user task
  - فیلترهای داشبورد و حسابداری
  - date picker جلالی سفارشی

- `assets/js/frontend.js`
  - انیمیشن progress
  - Modal مرحله
  - نمایش checklist و user task در فرانت
  - ارسال AJAX پاسخ مشتری
  - محاسبه خط تایم‌لاین در موبایل/دسکتاپ

- `assets/js/settings.js`
  - media uploader تنظیمات برندینگ

- `assets/js/wc-product.js`
  - مدیریت ردیف‌های پروژه خودکار در تب محصول ووکامرس

- `assets/css/admin.css`
  - استایل محیط ادمین، داشبورد، حسابداری، step editor

- `assets/css/frontend.css`
  - استایل فرانت، stepper، modal، user tasks، CTA گزارش

- `assets/css/settings.css`
  - استایل فیلدهای تنظیمات گزارش

## فایل‌های دیگر
- `README.md` فعلاً تقریباً خالی است.
- `project-management-dashboard-ui-05-1673124674.png`
  - تصویر الهام برای UI داشبورد

## قابلیت‌های فعلی پلاگین
- مدیریت پروژه برای مشتری
- اختصاص چند کارشناس به یک پروژه
- Stepper پروژه با وضعیت‌های `todo/current/done`
- چک‌لیست داخل هر مرحله
- تسک سمت مشتری و پاسخ مشتری از فرانت
- گزارش نهایی پروژه
- برندینگ گزارش
- پیامک webhook-based
- ساخت خودکار پروژه از سفارش ووکامرس
- داشبورد مدیریتی پروژه‌ها
- بخش حسابداری پروژه‌ها

## نکات اولیه برای ادامه توسعه
- README نیاز به تکمیل دارد.
- کد پلاگین نسبتاً بزرگ و ماژولار است و بهتر است تغییرات بعدی مرحله‌ای انجام شوند.
- بخش frontend.css چندین patch و override متوالی دارد؛ برای تغییر UI باید با دقت refactor شود.
- برای هر تغییر جدی در Stepper/Modal/Dashboard بهتر است اول scope دقیق مشخص شود.

## وضعیت فعلی
- هنوز هیچ تغییری در منطق پروژه اعمال نشده.
- فقط پروژه وارد workspace شده و بررسی اولیه ساختار انجام شده.
