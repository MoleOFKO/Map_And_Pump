<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="../../css/HQstyle.css">
</head>
<body>
    <h2 class="title">Station Network</h2>
    <p class="subtitle">Onboard physical station nodes and set up terminal operating profiles.</p>

    <div class="card">
        <h3>Register Station Node</h3>
        <p class="subtitle" style="margin-bottom: 12px;">Establish unique location tags for network reporting.</p>
        <div class="form-group">
            <label>Station Name/Location Code</label>
            <input type="text" placeholder="Site #014 - Downtown Metro">
        </div>
        <button class="btn" onclick="alert('Terminal Node created successfully.')">Register Node</button>
    </div>

    <div class="card">
        <h3>Onboard Site Manager Account</h3>
        <p class="subtitle" style="margin-bottom: 12px;">Provision manager profiles responsible for log and inventory oversight.</p>
        <form onsubmit="event.preventDefault(); alert('Manager setup completed. Access keys generated.');">
            <div class="form-group">
                <label>Assigned Node</label>
                <select>
                    <option>Site #014 - Downtown Metro</option>
                    <option>Site #009 - Highway Bypass</option>
                </select>
            </div>
            <div class="form-group">
                <label>Operator Professional Email</label>
                <input type="email" placeholder="manager.name@apex.com" required>
            </div>
            <button class="btn btn-success" type="submit">Deploy Manager Profile</button>
        </form>
    </div>
</body>
</html>