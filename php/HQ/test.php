<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/HQstyle.css">
</head>
<body>
    <h2 class="title">Brand Configuration</h2>
    <p class="subtitle">Set global brand styles, logos, and configurations.</p>
    
    <div class="card p-4">
        <!-- <h3 class="mb-4">Corporate Asset Branding</h3>
        <p class="subtitle" style="margin-bottom:15px;">Modify central details distributed downstream to individual terminals.</p>[cite: 2] -->
        
        <form onsubmit="saveBrandConfig(event)">
            <div class="row align-items-center">
                <!-- Logo Preview & Upload -->
                <div class="col-md-4 text-center border-end-md mb-4 mb-md-0">
                    <img id="logoPreview" src="https://via.placeholder.com/150" alt="Brand Logo" class="rounded-circle img-thumbnail mb-3" style="width: 150px; height: 150px; object-fit: cover; background-color: #fff;">
                    <div>
                        <input type="file" id="brandLogoInput" accept="image/*" style="display: none;" onchange="previewLogo(event)">
                        <label for="brandLogoInput" class="btn btn-sm btn-outline-secondary text-white">Change Logo</label>
                    </div>
                </div>

                <!-- Form Fields -->
                <div class="col-md-8 ps-md-4">
                    <div class="form-group mb-3">
                        <label class="form-label">Brand Display Title</label>
                        <input type="text" class="form-control" id="brandTitle" value="Apex Fuel & Gas" placeholder="Company Display Name">
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">Company Information & Details</label>
                        <textarea class="form-control custom-input" id="brandInfo" rows="3" placeholder="Enter corporate background, address, or details...">Apex Fuel & Gas - Global Headquarters, Operations, and Terminal Management Systems.</textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Corporate Email</label>
                            <input type="email" class="form-control" id="brandEmail" value="hq@apexfuelgas.com" placeholder="corp@example.com">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Support / Contact Phone</label>
                            <input type="text" class="form-control" id="brandPhone" value="+1 (800) 555-APEX" placeholder="+1 (xxx) xxx-xxxx">
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">Interface Primary Accent</label>
                        <div>
                            <input type="color" class="form-control" id="brandColor" value="#2563eb" style="width: 80px; height: 38px; padding: 2px; cursor: pointer;">
                        </div>
                    </div>

                    <div class="text-center">
                        <button type="submit" class="btn btn-primary px-4">Apply Brand Config</button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
        // Load saved brand data, logo, and settings on startup
        window.addEventListener('DOMContentLoaded', () => {
            const savedTitle = localStorage.getItem('brand_title');
            const savedInfo = localStorage.getItem('brand_info');
            const savedEmail = localStorage.getItem('brand_email');
            const savedPhone = localStorage.getItem('brand_phone');
            const savedColor = localStorage.getItem('brand_color');
            const savedLogo = localStorage.getItem('brand_logo');

            if (savedTitle) document.getElementById('brandTitle').value = savedTitle;
            if (savedInfo) document.getElementById('brandInfo').value = savedInfo;
            if (savedEmail) document.getElementById('brandEmail').value = savedEmail;
            if (savedPhone) document.getElementById('brandPhone').value = savedPhone;
            if (savedColor) document.getElementById('brandColor').value = savedColor;
            if (savedLogo) document.getElementById('logoPreview').src = savedLogo;
        });

        function previewLogo(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('logoPreview').src = e.target.result;
                    localStorage.setItem('brand_logo', e.target.result);
                }
                reader.readAsDataURL(file);
            }
        }

        function saveBrandConfig(event) {
            event.preventDefault();
            const title = document.getElementById('brandTitle').value;
            const info = document.getElementById('brandInfo').value;
            const email = document.getElementById('brandEmail').value;
            const phone = document.getElementById('brandPhone').value;
            const color = document.getElementById('brandColor').value;

            localStorage.setItem('brand_title', title);
            localStorage.setItem('brand_info', info);
            localStorage.setItem('brand_email', email);
            localStorage.setItem('brand_phone', phone);
            localStorage.setItem('brand_color', color);

            alert('Corporate parameters stored successfully!');
        }
    </script>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>