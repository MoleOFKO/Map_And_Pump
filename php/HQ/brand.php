<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="../../css/HQstyle.css">
</head>
<body>
    <h2 class="title">Brand Configuration</h2>
    <p class="subtitle">Set global brand styles, logos, and configurations.</p>
    
    <div class="card">
        <h3>Corporate Asset Branding</h3>
        <p class="subtitle" style="margin-bottom:15px;">Modify central details distributed downstream to individual terminals.</p>
        <div class="form-group">
            <label>Brand Display Title</label>
            <input type="text" value="Apex Fuel & Gas" placeholder="Company Display Name">
        </div>
        <div class="form-group">
            <label>Interface Primary Accent</label>
            <input type="color" value="#2563eb" style="height: 40px; padding: 2px; cursor: pointer;">
        </div>
        <button class="btn" onclick="alert('Corporate parameters stored successfully!')">Apply Brand Config</button>
    </div>
</body>
</html>