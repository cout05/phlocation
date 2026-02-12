<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dynamic Philippine Location Dropdown</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f4f9; color: #333; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #2c3e50; }
        h2 { border-bottom: 2px solid #eee; padding-bottom: 10px; margin-top: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        select, input[type="text"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; font-size: 16px; }
        select:focus, input:focus { border-color: #3498db; outline: none; }
        .zip-highlight { background-color: #e8f6f3; border-color: #1abc9c; font-weight: bold; color: #16a085; }

    </style>
</head>
<body>

<div class="container">
    <h1>Philippine Location Selector</h1>
    


    <!-- Option 1: City -> ZIP -->
    <h2>Option 1: City &rarr; ZIP Code</h2>
    <div class="form-group">
        <label for="opt1_city">City / Municipality:</label>
        <select id="opt1_city">
            <option value="">Select City</option>
            <!-- Populated via AJAX -->
        </select>
    </div>
    <div class="form-group">
        <label for="opt1_zip">ZIP Code:</label>
        <input type="text" id="opt1_zip" readonly class="zip-highlight" placeholder="Auto-filled">
    </div>

    <!-- Option 2: Province -> City -> Barangay -> ZIP -->
    <h2>Option 2: Province &rarr; City &rarr; Barangay &rarr; ZIP</h2>
    <div class="form-group">
        <label for="opt2_province">Province:</label>
        <select id="opt2_province">
            <option value="">Select Province</option>
            <!-- Populated via AJAX -->
        </select>
    </div>
    <div class="form-group">
        <label for="opt2_city">City / Municipality:</label>
        <select id="opt2_city" disabled>
            <option value="">Select Province First</option>
        </select>
    </div>
    <div class="form-group">
        <label for="opt2_barangay">Barangay:</label>
        <select id="opt2_barangay" disabled>
            <option value="">Select City First</option>
        </select>
    </div>
    <div class="form-group">
        <label for="opt2_zip">ZIP Code:</label>
        <input type="text" id="opt2_zip" readonly class="zip-highlight" placeholder="Auto-filled">
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- API Helper ---
    async function fetchData(action, params = {}) {
        const url = new URL('get_locations.php', window.location.href);
        url.searchParams.append('action', action);
        for (const key in params) {
            url.searchParams.append(key, params[key]);
        }
        
        try {
            const response = await fetch(url);
            if (!response.ok) throw new Error('Network response was not ok');
            return await response.json();
        } catch (error) {
            console.error('Fetch error:', error);
            return [];
        }
    }

    // --- Option 1 Logic ---
    const opt1City = document.getElementById('opt1_city');
    const opt1Zip = document.getElementById('opt1_zip');

    // Load Cities for Option 1
    fetchData('get_cities').then(data => {
        data.forEach(city => {
            const option = document.createElement('option');
            option.value = city.id;
            option.textContent = city.name;
            option.dataset.zip = city.zipcode || ''; // Store zip in dataset if available
            opt1City.appendChild(option);
        });
    });

    // On City Change
    opt1City.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        
        if (this.value) {
            fetchData('get_zip', { city_id: this.value }).then(data => {
                opt1Zip.value = data.zipcode || 'Not Found';
            });
        } else {
            opt1Zip.value = '';
        }
    });


    // --- Option 2 Logic ---
    const opt2Prov = document.getElementById('opt2_province');
    const opt2City = document.getElementById('opt2_city');
    const opt2Brgy = document.getElementById('opt2_barangay');
    const opt2Zip = document.getElementById('opt2_zip');

    // Load Provinces
    fetchData('get_provinces').then(data => {
        data.forEach(prov => {
            const option = document.createElement('option');
            option.value = prov.id;
            option.textContent = prov.name;
            opt2Prov.appendChild(option);
        });
    });

    // Province Change -> Load Cities
    opt2Prov.addEventListener('change', function() {
        // Reset Logic
        opt2City.innerHTML = '<option value="">Select City</option>';
        opt2City.disabled = true;
        opt2Brgy.innerHTML = '<option value="">Select City First</option>';
        opt2Brgy.disabled = true;
        opt2Zip.value = '';

        if (this.value) {
            fetchData('get_cities', { province_id: this.value }).then(data => {
                data.forEach(city => {
                    const option = document.createElement('option');
                    option.value = city.id;
                    option.textContent = city.name;
                    opt2City.appendChild(option);
                });
                opt2City.disabled = false;
            });
        }
    });

    // City Change -> Load Barangays & Auto-fill ZIP (City Level)
    opt2City.addEventListener('change', function() {
        opt2Brgy.innerHTML = '<option value="">Select Barangay</option>';
        opt2Brgy.disabled = true;
        opt2Zip.value = 'Loading...';

        if (this.value) {
            // Get City Zip first
            fetchData('get_zip', { city_id: this.value }).then(data => {
                opt2Zip.value = data.zipcode || '';
            });

            // Load Barangays
            fetchData('get_barangays', { city_id: this.value }).then(data => {
                data.forEach(brgy => {
                    const option = document.createElement('option');
                    option.value = brgy.id;
                    option.textContent = brgy.name;
                    opt2Brgy.appendChild(option);
                });
                opt2Brgy.disabled = false;
            });
        } else {
            opt2Zip.value = '';
        }
    });

    // Barangay Change -> Update ZIP (if specific to Brgy)
    opt2Brgy.addEventListener('change', function() {
        if (this.value) {
             fetchData('get_zip', { 
                 city_id: opt2City.value,
                 barangay_id: this.value 
             }).then(data => {
                 if (data.zipcode) {
                     opt2Zip.value = data.zipcode;
                 }
             });
        }
    });

});
</script>

</body>
</html>
