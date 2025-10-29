<?php
// geo.php - combined frontend + API

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if request is POST JSON for API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);

    $latitude = $input['latitude'] ?? null;
    $longitude = $input['longitude'] ?? null;

    if ($latitude === null || $longitude === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing latitude or longitude']);
        exit;
    }

    // Function to reverse geocode using Nominatim
    function reverseGeocode($lat, $lon) {
        $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lon}&zoom=18&addressdetails=1";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, "my_location_app"); // Required by Nominatim
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
          // DISABLE SSL verification (for local testing only)
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception('cURL error: ' . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Geocoding service returned HTTP code $httpCode");
        }

        return json_decode($response, true);
    }

    try {
        $location = reverseGeocode($latitude, $longitude);

        if (isset($location['address'])) {
            $address = $location['address'];

            $district = $address['state_district'] ?? 
                        $address['district'] ?? 
                        $address['county'] ?? 
                        $address['city_district'] ?? null;

            $city = $address['city'] ?? $address['town'] ?? $address['village'] ?? null;
            $suburb = $address['suburb'] ?? $address['neighbourhood'] ?? null;
            $postcode = $address['postcode'] ?? null;

            $result = [
                'country' => $address['country'] ?? null,
                'state' => $address['state'] ?? null,
                'district' => $district,
                'city' => $city,
                'suburb' => $suburb,
                'postcode' => $postcode
            ];

            echo json_encode($result);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Location not found']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Geocoding service error: ' . $e->getMessage()]);
    }

    exit; // End API processing
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Geocode District Finder</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        input, button { padding: 8px; margin: 5px 0; width: 200px; }
        .result { margin-top: 20px; padding: 10px; border: 1px solid #ccc; }
    </style>
</head>
<body>
    <h2>Find District from Latitude & Longitude</h2>

    <label>Latitude:</label><br>
    <input type="text" id="latitude" placeholder="Enter latitude"><br>

    <label>Longitude:</label><br>
    <input type="text" id="longitude" placeholder="Enter longitude"><br>

    <button id="findBtn">Find District</button>
    <button id="useLocationBtn">Use My Location</button>

    <div class="result" id="result"></div>

    <script>
        const resultDiv = document.getElementById('result');

        document.getElementById('findBtn').addEventListener('click', () => {
            const lat = document.getElementById('latitude').value.trim();
            const lon = document.getElementById('longitude').value.trim();
            if (!lat || !lon) {
                resultDiv.innerHTML = '<span style="color:red;">Please enter both latitude and longitude.</span>';
                return;
            }
            fetchDistrict(lat, lon);
        });

        document.getElementById('useLocationBtn').addEventListener('click', () => {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        document.getElementById('latitude').value = position.coords.latitude;
                        document.getElementById('longitude').value = position.coords.longitude;
                        fetchDistrict(position.coords.latitude, position.coords.longitude);
                    },
                    (error) => {
                        resultDiv.innerHTML = '<span style="color:red;">Geolocation error: ' + error.message + '</span>';
                    }
                );
            } else {
                resultDiv.innerHTML = '<span style="color:red;">Geolocation not supported by this browser.</span>';
            }
        });

        function fetchDistrict(lat, lon) {
            resultDiv.innerHTML = 'Loading...';
            fetch(window.location.href, { // Send to same page
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ latitude: lat, longitude: lon })
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    resultDiv.innerHTML = '<span style="color:red;">Error: ' + data.error + '</span>';
                } else {
                    resultDiv.innerHTML = `
                        <strong>Country:</strong> ${data.country || '-'}<br>
                        <strong>State:</strong> ${data.state || '-'}<br>
                        <strong>District:</strong> ${data.district || '-'}<br>
                        <strong>City:</strong> ${data.city || '-'}<br>
                        <strong>Suburb:</strong> ${data.suburb || '-'}<br>
                        <strong>Postcode:</strong> ${data.postcode || '-'}
                    `;
                }
            })
            .catch(err => {
                resultDiv.innerHTML = '<span style="color:red;">Error: ' + err + '</span>';
            });
        }
    </script>
</body>
</html>
