<?php

class GeoTracker extends IPSModule {

    public function __construct($InstanceID) {
        parent::__construct($InstanceID);

        // Aktivieren Sie die Fehlerberichterstattung
        error_reporting(E_ALL);
        $this->SendDebug('Constructor', 'Module constructed', 0);
    }

    public function Create() {
        parent::Create();

        // Überprüfen Sie die Installationsbedingungen
        $this->checkInstallationConditions();

        $this->RegisterPropertyInteger('Latitude', 0);
        $this->RegisterPropertyInteger('Longitude', 0);
        $this->RegisterPropertyInteger('Altitude', 0);
        $this->RegisterPropertyInteger('Speed', 0);

        $this->RegisterPropertyString('GoogleMapsAPIKey', '');

        $this->RegisterVariableString('MapHTMLBox', 'Map', '~HTMLBox');
        $this->RegisterVariableString('Address', 'Address', '');  // Neue Variable für die Adresse
        $this->RegisterAttributeInteger('LastAddressUpdate', 0);  // Add a new attribute to store the last update time
        $this->SendDebug('Create', 'Module created and properties registered', 0);
    }

private function getOpenStreetMapAddress($latitude, $longitude) {
    $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat=$latitude&lon=$longitude&zoom=18&addressdetails=1";
    $json = file_get_contents($url);
    $data = json_decode($json, true);
    if (isset($data['display_name'])) {
        return $data['display_name'];
    } else {
        return 'Address not found';
    }
}

    
private function validateVariableId($id) {
    if ($id == 0) {
        return false;
    }
    if (!IPS_VariableExists($id)) {
        return false;
    }
    return true;
}    

private function loadGpsDataFromArchive($archiveId, $latitudeId, $longitudeId, $altitudeId, $speedId) {
    // Hier müssen Sie den Code hinzufügen, um die GPS-Daten aus dem Archiv zu laden
    // Sie können die Funktion AC_GetLoggedValues verwenden, um die geloggten Werte einer Variablen zu erhalten
    // Sie müssen dies für jede der GPS-Variablen tun und die Ergebnisse in einem Array zusammenfassen

    $gpsData = [];

    // Get today's date
    $today = date("Y-m-d");

    // Get the logged values for each variable
    $latitudeValues = AC_GetLoggedValues($archiveId, $latitudeId, strtotime($today), time(), 0);
    $longitudeValues = AC_GetLoggedValues($archiveId, $longitudeId, strtotime($today), time(), 0);
    $altitudeValues = AC_GetLoggedValues($archiveId, $altitudeId, strtotime($today), time(), 0);
    $speedValues = AC_GetLoggedValues($archiveId, $speedId, strtotime($today), time(), 0);

    // Combine the values into a single array
    for ($i = 0; $i < count($latitudeValues); $i++) {
        $gpsData[] = [
            'latitude' => $latitudeValues[$i]['Value'],
            'longitude' => $longitudeValues[$i]['Value'],
            'altitude' => $altitudeValues[$i]['Value'],
            'speed' => $speedValues[$i]['Value']
        ];
    }

    return $gpsData;
}

private function generateOpenStreetMapHtml($gpsData) {
    $htmlCode = '<div id="mapid" style="height: 400px;"></div>';
    $htmlCode .= '<script>';
    $htmlCode .= 'var mymap = L.map("mapid").setView([51.505, -0.09], 13);';
    $htmlCode .= 'L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {';
    $htmlCode .= 'maxZoom: 19,';
    $htmlCode .= '}).addTo(mymap);';

    foreach ($gpsData as $dataPoint) {
        $htmlCode .= 'L.marker([' . $dataPoint['latitude'] . ', ' . $dataPoint['longitude'] . ']).addTo(mymap)';
        $htmlCode .= '.bindPopup("Speed: ' . $dataPoint['speed'] . ' km/h, Altitude: ' . $dataPoint['altitude'] . ' meters");';
    }

    $htmlCode .= '</script>';

    return $htmlCode;
}

    
    
private function validateValues($latitude, $longitude, $altitude, $speed) {
    if (!is_numeric($latitude) || !is_numeric($longitude) || !is_numeric($altitude) || !is_numeric($speed)) {
        return false;
    }
    if ($latitude < -90 || $latitude > 90) {
        return false;
    }
    if ($longitude < -180 || $longitude > 180) {
        return false;
    }
    if ($altitude < -10000 || $altitude > 10000) {
        return false;
    }
    if ($speed < 0) {
        return false;
    }
    return true;
}


private function validateAPIKey($apiKey) {
    if (empty($apiKey)) {
        return false;
    }

    // Senden Sie eine einfache Anfrage an die Google Maps API
    $url = "https://maps.googleapis.com/maps/api/geocode/json?latlng=40.714224,-73.961452&key=$apiKey";
    $response = file_get_contents($url);
    $data = json_decode($response, true);

    // Überprüfen Sie, ob die Antwort einen Fehler enthält, der auf einen ungültigen API-Schlüssel hinweist
    if (isset($data['error_message'])) {
        $this->LogMessage("Invalid Google Maps API key: " . $data['error_message'], KL_ERROR);
        return false;
    }

    return true;
}

private function writeToFile($data) {
    $filePath = __DIR__ . '/geotracking.json';
    $jsonData = json_encode($data);
    if (file_put_contents($filePath, $jsonData) === false) {
        return false;
    }
    return true;
}

private function readFromFile() {
    $filePath = __DIR__ . '/geotracking.json';
    if (!file_exists($filePath)) {
        return null;
    }
    $jsonData = file_get_contents($filePath);
    if ($jsonData === false) {
        return null;
    }
    return json_decode($jsonData, true); // Setzen Sie den zweiten Parameter auf true, um ein Array zurückzugeben
}


    
public function ApplyChanges() {
        parent::ApplyChanges();

        // Register for updates of the source variables
        $latitudeId = $this->ReadPropertyInteger('Latitude');
        $longitudeId = $this->ReadPropertyInteger('Longitude');
        $altitudeId = $this->ReadPropertyInteger('Altitude');
        $speedId = $this->ReadPropertyInteger('Speed');

        if ($latitudeId > 0) {
            $this->RegisterMessage($latitudeId, VM_UPDATE);
        }
        if ($longitudeId > 0) {
            $this->RegisterMessage($longitudeId, VM_UPDATE);
        }
        if ($altitudeId > 0) {
            $this->RegisterMessage($altitudeId, VM_UPDATE);
        }
        if ($speedId > 0) {
            $this->RegisterMessage($speedId, VM_UPDATE);
        }

        // Update the map when the module is updated
        $this->UpdateGeotracking();
        $this->SendDebug('ApplyChanges', 'Changes applied and geotracking updated', 0);
    }



    public function MessageSink($TimeStamp, $SenderId, $Message, $Data) {
        parent::MessageSink($TimeStamp, $SenderId, $Message, $Data);

        // Update the map when one of the source variables is updated
        if ($Message == VM_UPDATE) {
            $this->UpdateGeotracking();
        }
    }

    public function GeoTracker_UpdateGeotracking() {
        $this->UpdateGeotracking();
    }
    
    public function UpdateGeotracking() {
    // Get the variable IDs
    $latitudeId = $this->ReadPropertyInteger('Latitude');
    $longitudeId = $this->ReadPropertyInteger('Longitude');
    $altitudeId = $this->ReadPropertyInteger('Altitude');
    $speedId = $this->ReadPropertyInteger('Speed');

   // Get the Archive Handler instance ID
    $instanceID = IPS_GetInstance($this->InstanceID)['ConnectionID'];

    // Check if the instance ID is valid
    if ($instanceID !== false) {
    // Get the archive handler ID
    $archiveHandlerID = IPS_GetInstance($instanceID)['ModuleInfo']['ModuleID'];
    } else {
    // Handle the case when the archive handler instance is not found
    // You can set a default value or handle the error accordingly
    $archiveHandlerID = 0;
    }

// Check if the variables exist and enable logging for them
if (IPS_VariableExists($latitudeId)) {
    AC_SetLoggingStatus($archiveHandlerID, $latitudeId, true);
}
if (IPS_VariableExists($longitudeId)) {
    AC_SetLoggingStatus($archiveHandlerID, $longitudeId, true);
}
if (IPS_VariableExists($altitudeId)) {
    AC_SetLoggingStatus($archiveHandlerID, $altitudeId, true);
}
if (IPS_VariableExists($speedId)) {
    AC_SetLoggingStatus($archiveHandlerID, $speedId, true);
}


    $this->SendDebug('UpdateGeotracking', 'Starting geotracking update', 0);
    $this->SendDebug('UpdateGeotracking', 'Latitude: ' . $latitude . ', Longitude: ' . $longitude . ', Altitude: ' . $altitude . ', Speed: ' . $speed, 0);

    // Check if 120 seconds have passed since the last address update
    $lastUpdate = $this->ReadAttributeInteger('LastAddressUpdate');
    if (time() - $lastUpdate >= 120) {
        // Check if latitude and longitude are not null before getting the address
        if ($latitude !== null && $longitude !== null) {
        // Get the address for the current GPS coordinates
        $address = $this->getOpenStreetMapAddress($latitude, $longitude);
        SetValue($this->GetIDForIdent('Address'), $address);  // Store the address in the new variable

        // Update the last update time
        $this->WriteAttributeInteger('LastAddressUpdate', time());
        }
    }

        
    // Find the archive instance
    $archiveInstances = IPS_GetInstanceListByModuleID("{43192F0B-135B-4CE7-A0A7-1475603F3060}");
    if (count($archiveInstances) == 0) {
        $this->LogMessage("No archive instance found", KL_ERROR);
        return;
    }
    $archiveId = $archiveInstances[0];  // Use the first archive instance

    // Load the GPS data from the archive
    $gpsData = $this->loadGpsDataFromArchive($archiveId, $latitudeId, $longitudeId, $altitudeId, $speedId);

    // Generate the HTML code for the OpenStreetMap map
    $htmlCode = $this->generateOpenStreetMapHtml($gpsData);

    // Update the HTMLBox with the new HTML code
    SetValue($this->GetIDForIdent('MapHTMLBox'), $htmlCode);
    
    

    $geotrackingData = [
        'latitude' => $latitude,
        'longitude' => $longitude,
        'altitude' => $altitude,
        'speed' => $speed
    ];

    $this->SetBuffer('GeotrackingData', json_encode($geotrackingData));

    if (!$this->writeToFile($geotrackingData)) {
        $this->LogMessage("Failed to write to file", KL_ERROR);
        return;
    }

    $this->LogMessage("Successfully wrote to file", KL_NOTIFY);

    $data = $this->readFromFile();
    if ($data === null) {
        $this->LogMessage("Failed to read from file", KL_ERROR);
        return;
    }

    $this->LogMessage("Successfully read from file", KL_NOTIFY);

    
}


    private function generateHTML($data, $googleMapsAPIKey, $latitude, $longitude) {
        $htmlCode = '<!DOCTYPE html>
                    <html>
                    <body>
                    <div id="map" style="height: 400px; width: 500px;"></div>
                    <script>
                    function initMap() {
                        var map = new google.maps.Map(document.getElementById("map"), {
                            zoom: 6,
                            center: {lat: ' . $latitude . ', lng: ' . $longitude . '},
                            mapTypeId: "terrain"
                        });

                        var flightPlanCoordinates = [';

        foreach($data as $item) {
            $htmlCode .= '{lat: ' . $item['latitude'] . ', lng: ' . $item['longitude'] . '},';
        }

        $htmlCode .= '];

        var flightPath = new google.maps.Polyline({
                            path: flightPlanCoordinates,
                            geodesic: true,
                            strokeColor: "#FF0000",
                            strokeOpacity: 1.0,
                            strokeWeight: 2
                        });

        flightPath.setMap(map);';

        foreach($data as $item) {
            $htmlCode .= 'var marker = new google.maps.Marker({
                              position: {lat: ' . $item['latitude'] . ', lng: ' . $item['longitude'] . '},
                              map: map,
                              title: "Speed: ' . $item['speed'] . ' km/h, Altitude: ' . $item['altitude'] . ' meters"
                          });';
        }

        $htmlCode .= '}
                    </script>
                    <script async defer src="https://maps.googleapis.com/maps/api/js?key=' . $googleMapsAPIKey . '&callback=initMap">
                    </script>
                    </body>
                    </html>';

        return $htmlCode;
    }

    private function updateHTMLBox($htmlCode) {
        return SetValue($this->GetIDForIdent('MapHTMLBox'), $htmlCode) !== false;
    }

    private function checkInstallationConditions() {
        // Überprüfen Sie die PHP-Version
        if (version_compare(PHP_VERSION, '7.2.0', '<')) {
            $this->LogMessage("PHP version 7.2.0 or higher is required", KL_ERROR);
            return;
        }

        // Überprüfen Sie die Verfügbarkeit der cURL-Erweiterung (wird für API-Anfragen benötigt)
        if (!extension_loaded('curl')) {
            $this->LogMessage("The cURL PHP extension is required", KL_ERROR);
            return;
        }

        // Überprüfen Sie die Schreibrechte für das Verzeichnis, in das die Geotracking-Daten geschrieben werden
        if (!is_writable('/var/bin/symcon/modules/Geofence')) {
            $this->LogMessage("The directory /var/bin/symcon/modules/Geofence is not writable", KL_ERROR);
            return;
        }

        // Überprüfen Sie die Internetverbindung
        $connected = @fsockopen("www.google.com", 80); 
        if (!$connected){
            $this->LogMessage("Internet connection is required", KL_ERROR);
            return;
        }
        fclose($connected);
    }
}
