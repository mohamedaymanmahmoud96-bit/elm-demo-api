<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تم الدفع بنجاح</title>
    <style>
        body { font-family: sans-serif; background: #fff7f0; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: white; padding: 32px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,.08); text-align: center; max-width: 420px; }
        h1 { color: #e87817; }
    </style>
</head>
<body>
    <div class="card">
        <h1>تم الدفع بنجاح</h1>
        <p>تم تأكيد عملية الدفع التجريبية وتفعيل الاشتراك.</p>
        <p>رقم العملية: {{ $payment->id }}</p>
        <p>المبلغ: {{ $payment->amount }} {{ $payment->currency }}</p>
        <p>يمكنك العودة إلى التطبيق الآن.</p>
    </div>
</body>
</html>
