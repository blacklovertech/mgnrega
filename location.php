<?php
require_once 'db.php';
require_once 'functions.php';

// Handle AJAX request for reverse geocoding
if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
    strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $latitude = $input['latitude'] ?? null;
    $longitude = $input['longitude'] ?? null;
    
    if ($latitude === null || $longitude === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing latitude or longitude']);
        exit;
    }
    
    try {
        $location = reverseGeocode($latitude, $longitude);
        
        if (isset($location['address'])) {
            $address = $location['address'];
            
            $district = $address['state_district'] ?? 
                       $address['district'] ?? 
                       $address['county'] ?? null;
            
            // Try to match with districts in database
            $matchedDistrict = null;
            if ($district) {
                $districts = getDistricts();
                foreach ($districts as $dbDistrict) {
                    if (stripos($dbDistrict, $district) !== false || 
                        stripos($district, $dbDistrict) !== false) {
                        $matchedDistrict = $dbDistrict;
                        break;
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'country' => $address['country'] ?? null,
                'state' => $address['state'] ?? null,
                'district' => $district,
                'matched_district' => $matchedDistrict,
                'city' => $address['city'] ?? $address['town'] ?? $address['village'] ?? null,
                'postcode' => $address['postcode'] ?? null
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Location not found']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Geocoding error: ' . $e->getMessage()]);
    }
    
    exit;
}

// Reverse geocode function
function reverseGeocode($lat, $lon) {
    $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lon}&zoom=10&addressdetails=1";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => "MGNREGA_Dashboard_App",
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
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
?>
<!DOCTYPE html>
<html lang="ta">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>மாவட்டத்தைக் கண்டறிய - MGNREGA டாஷ்போர்டு</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        .bg-primary-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .bg-success-gradient {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        .bg-warning-gradient {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .bg-danger-gradient {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }
        .stat-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .btn-action {
            border-radius: 10px;
            font-weight: 600;
            padding: 12px 24px;
            transition: all 0.3s ease;
        }
        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .section-title {
            border-left: 5px solid #667eea;
            padding-left: 15px;
            margin-bottom: 25px;
        }
        .language-badge {
            background: linear-gradient(45deg, #FF9933, #FF9933, #138808, #138808);
            color: white;
            font-weight: bold;
        }
        .card-hover:hover {
            transform: translateY(-5px);
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15) !important;
        }
        .pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: .5; }
        }
        .step-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin: 0 auto 15px;
        }
        .loading-spinner {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="bg-light">
    <!-- Header -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary-gradient shadow">
        <div class="container">
            <a class="navbar-brand fw-bold" href="#">
                <i class="bi bi-graph-up me-2"></i>
                MGNREGA டாஷ்போர்டு
            </a>
            <span class="badge language-badge">தமிழ்</span>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a href="index.php" class="nav-link">
                            <i class="bi bi-house me-1"></i>முகப்பு
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="sync.php" class="nav-link">
                            <i class="bi bi-arrow-repeat me-1"></i>தரவு ஒத்திசைவு
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="location.php" class="nav-link active">
                            <i class="bi bi-geo-alt me-1"></i>மாவட்டத்தைக் கண்டறிய
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container my-5">
        <!-- How It Works -->
        <div class="row mb-5">
            <div class="col-12">
                <div class="card stat-card">
                    <div class="card-header bg-white border-0 py-3">
                        <h3 class="section-title mb-0">
                            <i class="bi bi-info-circle me-2"></i>இது எவ்வாறு செயல்படுகிறது? (How It Works?)
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <div class="col-md-6 col-lg-3">
                                <div class="text-center">
                                    <div class="step-icon bg-primary-gradient text-white">
                                        <i class="bi bi-geo"></i>
                                    </div>
                                    <h5 class="fw-bold">Click Detect Button</h5>
                                    <p class="text-muted small">"என் இருப்பிடத்தைக் கண்டறியவும்" பொத்தானைக் கிளிக் செய்யவும்</p>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <div class="text-center">
                                    <div class="step-icon bg-success-gradient text-white">
                                        <i class="bi bi-check-lg"></i>
                                    </div>
                                    <h5 class="fw-bold">Allow Access</h5>
                                    <p class="text-muted small">கேட்கும்போது இருப்பிட அனுமதியை வழங்கவும்</p>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <div class="text-center">
                                    <div class="step-icon bg-warning-gradient text-white">
                                        <i class="bi bi-search"></i>
                                    </div>
                                    <h5 class="fw-bold">Automatic Detection</h5>
                                    <p class="text-muted small">உங்கள் மாவட்டத்தை தானாகவே கண்டறிவோம்</p>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <div class="text-center">
                                    <div class="step-icon bg-danger-gradient text-white">
                                        <i class="bi bi-graph-up"></i>
                                    </div>
                                    <h5 class="fw-bold">View Data</h5>
                                    <p class="text-muted small">உங்கள் மாவட்டத்திற்கான MGNREGA தரவைக் காண்க</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Location Detection -->
        <div class="row mb-5">
            <div class="col-12">
                <div class="card stat-card card-hover">
                    <div class="card-body text-center p-5">
                        <div class="mb-4">
                            <i class="bi bi-globe-asia-australia text-primary" style="font-size: 4rem;"></i>
                        </div>
                        <h2 class="card-title text-dark mb-3">உங்கள் இருப்பிடத்தைக் கண்டறியவும்</h2>
                        <p class="text-muted mb-4">Detect Your Location Automatically</p>
                        
                        <button id="detectBtn" class="btn btn-primary btn-action px-5 py-3">
                            <i class="bi bi-geo-alt-fill me-2"></i>என் இருப்பிடத்தைக் கண்டறியவும்
                        </button>
                        
                        <div id="status" class="mt-4 min-h-50"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Location Result -->
        <div id="result" class="row mb-5 d-none">
            <div class="col-12">
                <div class="card stat-card">
                    <div class="card-header bg-success-gradient text-white py-3">
                        <h4 class="card-title mb-0">
                            <i class="bi bi-check-circle me-2"></i>உங்கள் இருப்பிடம் (Your Location)
                        </h4>
                    </div>
                    <div class="card-body">
                        <div id="locationDetails" class="row g-3 mb-4"></div>
                        <div id="actionButtons" class="text-center"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Privacy Notice -->
        <div class="row mb-5">
            <div class="col-12">
                <div class="alert alert-warning border-warning">
                    <div class="d-flex">
                        <div class="flex-shrink-0">
                            <i class="bi bi-shield-lock-fill text-warning" style="font-size: 1.5rem;"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h5 class="alert-heading">தனியுரிமை அறிவிப்பு (Privacy Notice)</h5>
                            <p class="mb-2">
                                Your location data is only used to detect your district and is NOT stored on our servers. 
                                We respect your privacy and only use this information to show you relevant MGNREGA data.
                            </p>
                            <p class="mb-0 small">
                                உங்கள் இருப்பிட தரவு உங்கள் மாவட்டத்தைக் கண்டறிய மட்டுமே பயன்படுத்தப்படுகிறது மற்றும் எங்கள் சேவையகங்களில் சேமிக்கப்படாது.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Manual Entry -->
        <div class="row">
            <div class="col-12">
                <div class="card stat-card">
                    <div class="card-header bg-secondary text-white py-3">
                        <h4 class="card-title mb-0">
                            <i class="bi bi-keyboard me-2"></i>அல்லது கைமுறையாக உள்ளிடவும் (Or Enter Manually)
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Latitude (அட்சரேகை)</label>
                                <input type="text" id="manualLat" class="form-control form-control-lg" placeholder="e.g., 13.0827">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Longitude (தீர்க்கரேகை)</label>
                                <input type="text" id="manualLon" class="form-control form-control-lg" placeholder="e.g., 80.2707">
                            </div>
                        </div>
                        <div class="mt-4 text-center">
                            <button id="manualBtn" class="btn btn-success btn-action px-5">
                                <i class="bi bi-search me-2"></i>மாவட்டத்தைக் கண்டறியவும்
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-white py-4 mt-5">
        <div class="container text-center">
            <p class="mb-2">
                <i class="bi bi-cpu me-1"></i>
                MGNREGA டாஷ்போர்டு - தரவு வெளிப்படைத்தன்மை மூலம் கிராமப்புற இந்தியாவை மேம்படுத்துதல்
            </p>
            <p class="mb-0 small opacity-75">
                &copy; 2024 MGNREGA Dashboard. All rights reserved.
            </p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const detectBtn = document.getElementById('detectBtn');
    const manualBtn = document.getElementById('manualBtn');
    const status = document.getElementById('status');
    const result = document.getElementById('result');
    const locationDetails = document.getElementById('locationDetails');
    const actionButtons = document.getElementById('actionButtons');

    // Store original button text
    detectBtn.dataset.originalText = detectBtn.innerHTML;
    manualBtn.dataset.originalText = manualBtn.innerHTML;

    // Set button loading state
    function setButtonLoading(button, isLoading) {
        if (isLoading) {
            button.disabled = true;
            button.innerHTML = `<span class="loading-spinner"><i class="bi bi-arrow-repeat"></i></span> ${button.dataset.originalText}`;
        } else {
            button.disabled = false;
            button.innerHTML = button.dataset.originalText;
        }
    }

    detectBtn.addEventListener('click', () => {
        if (!navigator.geolocation) {
            showError('Geolocation is not supported by your browser');
            return;
        }

        status.innerHTML = `
            <div class="alert alert-info d-flex align-items-center">
                <div class="spinner-border spinner-border-sm me-3" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <span class="pulse">🔍 உங்கள் இருப்பிடத்தைக் கண்டறிகிறது...</span>
            </div>
        `;
        setButtonLoading(detectBtn, true);

        navigator.geolocation.getCurrentPosition(
            position => findDistrict(position.coords.latitude, position.coords.longitude),
            error => {
                setButtonLoading(detectBtn, false);
                if (error.code === error.PERMISSION_DENIED) {
                    showError('Location access denied. Please enable location permissions and try again.');
                } else if (error.code === error.POSITION_UNAVAILABLE) {
                    showError('Location information unavailable. Please try again or enter coordinates manually.');
                } else {
                    showError('Unable to detect location. Please try again or enter coordinates manually.');
                }
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
        );
    });

    manualBtn.addEventListener('click', () => {
        const lat = document.getElementById('manualLat').value.trim();
        const lon = document.getElementById('manualLon').value.trim();
        
        if (!lat || !lon) {
            showError('Please enter both latitude and longitude');
            return;
        }
        
        if (isNaN(lat) || isNaN(lon)) {
            showError('Please enter valid numeric coordinates');
            return;
        }
        
        findDistrict(parseFloat(lat), parseFloat(lon));
    });

    async function findDistrict(lat, lon) {
        status.innerHTML = `
            <div class="alert alert-info d-flex align-items-center">
                <div class="spinner-border spinner-border-sm me-3" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <span class="pulse">🔍 உங்கள் மாவட்டத்தைக் கண்டறிகிறது...</span>
            </div>
        `;
        
        try {
            const response = await fetch('location.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ latitude: lat, longitude: lon })
            });
            
            const data = await response.json();
            
            if (data.error) {
                showError(data.error);
                return;
            }
            
            displayLocation(data);
        } catch (error) {
            showError('Network error. Please check your connection and try again.');
        } finally {
            setButtonLoading(detectBtn, false);
            setButtonLoading(manualBtn, false);
        }
    }

    function displayLocation(data) {
        status.innerHTML = '';
        result.classList.remove('d-none');
        
        locationDetails.innerHTML = `
            <div class="col-md-6">
                <div class="card bg-light border-0">
                    <div class="card-body text-center">
                        <i class="bi bi-building text-primary mb-2" style="font-size: 2rem;"></i>
                        <h6 class="card-subtitle mb-1 text-muted">State / மாநிலம்</h6>
                        <h5 class="card-title text-primary">${data.state || 'Not found'}</h5>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-light border-0">
                    <div class="card-body text-center">
                        <i class="bi bi-map text-success mb-2" style="font-size: 2rem;"></i>
                        <h6 class="card-subtitle mb-1 text-muted">District / மாவட்டம்</h6>
                        <h5 class="card-title text-success">${data.district || 'Not found'}</h5>
                    </div>
                </div>
            </div>
            ${data.city ? `
            <div class="col-md-6">
                <div class="card bg-light border-0">
                    <div class="card-body text-center">
                        <i class="bi bi-geo-alt text-warning mb-2" style="font-size: 2rem;"></i>
                        <h6 class="card-subtitle mb-1 text-muted">City / நகரம்</h6>
                        <h5 class="card-title text-warning">${data.city}</h5>
                    </div>
                </div>
            </div>
            ` : ''}
            ${data.country ? `
            <div class="col-md-6">
                <div class="card bg-light border-0">
                    <div class="card-body text-center">
                        <i class="bi bi-flag text-info mb-2" style="font-size: 2rem;"></i>
                        <h6 class="card-subtitle mb-1 text-muted">Country / நாடு</h6>
                        <h5 class="card-title text-info">${data.country}</h5>
                    </div>
                </div>
            </div>
            ` : ''}
        `;
        
        if (data.matched_district) {
            actionButtons.innerHTML = `
                <a href="index.php?district=${encodeURIComponent(data.matched_district)}" 
                   class="btn btn-success btn-action px-5">
                    <i class="bi bi-graph-up me-2"></i>${data.matched_district} -க்கான டாஷ்போர்டைக் காண்க
                </a>
            `;
        } else {
            actionButtons.innerHTML = `
                <div class="text-center">
                    <div class="alert alert-warning mb-3">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        "${data.district}" மாவட்டம் எங்கள் தரவுத்தளத்தில் காணப்படவில்லை
                    </div>
                    <a href="index.php" class="btn btn-primary btn-action">
                        <i class="bi bi-list-ul me-2"></i>கைமுறையாக மாவட்டத்தைத் தேர்ந்தெடுக்கவும்
                    </a>
                </div>
            `;
        }
    }

    function showError(message) {
        status.innerHTML = `
            <div class="alert alert-danger d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                ${message}
            </div>
        `;
    }

    // Enter key support for manual inputs
    document.getElementById('manualLat').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            manualBtn.click();
        }
    });
    
    document.getElementById('manualLon').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            manualBtn.click();
        }
    });
    </script>
</body>
</html>