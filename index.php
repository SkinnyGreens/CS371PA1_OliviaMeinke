<?php
const DATAPATH = __DIR__ . "/";
const LOGPATH = __DIR__ . "/API.log";

// You probably wouldn't use these actual headers in a production environment
// As they are too permissive for safe CORS handling, but are useful for testing and development
// They basically allow anything from anywhere
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Origin,Accept, X-Requested-With, Content-Type, Access-Control-Request-Method, Access-Control-Request-Headers");
header("Access-Control-Allow-Credentials: true");

// Pull some stuff out into variables for easier access
$requestMethod = $_SERVER['REQUEST_METHOD'];            // POST, GET, DELETE, etc. 
$request = $_SERVER['REQUEST_URI'];                     // What exactly did the client request?
$requestArray = explode('/', trim($request, '/'));      // Break it up into parts
$requestAction = explode('?',$requestArray[count($requestArray) - 1 ])[0]; // Last part before any query string is 'action'
$requestComponents = array_slice($requestArray, 2);     // Components after the base path in case we need those later

// Helper function to log messages to a file on the server. Choose the path carefully to avoid security issues.
// This is useful for debugging and tracking API usage.
function fileLog( $message ) {
    $timeStamp = date("Y-m-d H:i:s");
    $logEntry = "[$timeStamp] $message\n";
    file_put_contents(LOGPATH, $logEntry, FILE_APPEND);
}

// Normalize fish object to ensure all required keys are present
function normalizeFish($fish) {
    // Ensure aggression is a number between 1-5
    $aggression = isset($fish['aggression']) ? intval($fish['aggression']) : 3;
    if ($aggression < 1 || $aggression > 5) {
        $aggression = 3; // Default to 3 if out of range
    }
    
    // Validate size - must be one of: small, medium, large, extra-large
    $validSizes = ['small', 'medium', 'large', 'extra-large'];
    $size = isset($fish['size']) ? trim($fish['size']) : 'small';
    
    // Map common variations to canonical 'extra-large' etc.
    if (strcasecmp($size, 'Extra Large') === 0 || strcasecmp($size, 'extra_large') === 0 || strcasecmp($size, 'extra large') === 0) {
        $size = 'extra-large';
    }
    
    // Case-insensitive matching
    $sizeFound = false;
    foreach ($validSizes as $validSize) {
        if (strcasecmp($size, $validSize) === 0) {
            $size = $validSize; // Use the canonical case (e.g. lowercase)
            $sizeFound = true;
            break;
        }
    }
    if (!$sizeFound) {
        $size = 'small'; // Default to small if invalid
    }
    
    // Validate waterType - must be one of: fresh, salt, brackish
    $validWaterTypes = ['fresh', 'salt', 'brackish'];
    $waterType = isset($fish['waterType']) ? trim($fish['waterType']) : 'fresh';
    
    // Map common variations to canonical 'salt', 'fresh'
    if (strcasecmp($waterType, 'Saltwater') === 0 || strcasecmp($waterType, 'salt water') === 0) {
        $waterType = 'salt';
    } elseif (strcasecmp($waterType, 'Freshwater') === 0 || strcasecmp($waterType, 'fresh water') === 0) {
        $waterType = 'fresh';
    }
    
    $waterTypeFound = false;
    foreach ($validWaterTypes as $validType) {
        if (strcasecmp($waterType, $validType) === 0) {
            $waterType = $validType; // Use the canonical case
            $waterTypeFound = true;
            break;
        }
    }
    if (!$waterTypeFound) {
        $waterType = 'fresh'; // Default to fresh if invalid
    }
    
    return [
        'name' => $fish['name'] ?? '',
        'waterType' => $waterType,
        'aggression' => $aggression,
        'size' => $size
    ];
}

// use the php 8 match expression to route the request based on method and action.
try {
    // First see what (HTTP) request METHOD we have
    $status = match($requestMethod) {
        // OPTIONS is sometimes used for CORS preflight checks by browsers, so will always respond with 200
        'OPTIONS' => http_response_code(200),
        // Now match on the action part of the URI and call the appropriate function.
        // Funtion will return JSON encoded string and/or set appropriate HTTP response code on error
        'POST' => match($requestAction) {
            'addFish' => addFish($requestComponents),
            // return 404 if there is no matching action
            default => http_response_code(404),
        },
        'GET' => match($requestAction) {
            'getFish' => json_encode( getFish($requestComponents), JSON_PRETTY_PRINT),
            'noop' => noop($requestComponents),
            default => http_response_code(409),
        },
        'PUT' => match($requestAction) {
            'updateFish' => updateFish($requestComponents),
            'noop' => noop($requestComponents),
            default => http_response_code(404),
        },
        'DELETE' => match($requestAction) {
            'deleteFish' => deleteFish($requestComponents),
            'noop' => noop($requestComponents),
            default => http_response_code(404),
        },
        // We don't recognize this method, so return 404
        default => http_response_code(404),
    };
} catch (Exception $e) {
    // In case of trouble, report a 500 error
    http_response_code(500);
    // and return a JSON error message
    $status = json_encode(["error" => $e->getMessage()]);
}
// Record the request and status in the log file
fileLog("$requestMethod $requestAction - $status");
// Send the response back to the client
echo $status;

// Implementation of actions 

//No-Operation action for testing and validation of inputs
function noop($requestComponents) {
    // Use a new stdClass to build a response, this makes it easy to convert to JSON
    $rj = new stdClass();
    $input = json_decode(file_get_contents('php://input'));
    $rj->status = "noop called";
    $rj->rc = $requestComponents;
    $rj->input = $input;
    fileLog( "noop called with rc=" . var_export( $rj, true ) );
    return json_encode($rj);
}

// Delete a fish, we are not currently using $requestComponents but might in the future
function deleteFish($requestComponents) {
    fileLog( "deleteFish called with rc=" . var_export( $requestComponents, true ) );
    // Read the (JSON) input from the request body
    $delFish = (array) json_decode(file_get_contents('php://input'));
    fileLog( "deleteFish called with " . var_export( $delFish, true ) );
    $rj = new stdClass();
    // Sanitize name...
    $delFish['name'] = sanitizeNameForPath($delFish['name']); 
    // Get list of current fish, pass in $requestComponents for possible future use
    $currentFish = getFish($requestComponents);
    // Can't delete a fish that doesn't exist
    if (!isset($currentFish[$delFish['name']])) {
        http_response_code(404);
        $rj->status = "Fish not found";
        // To replicate the format used elsewhere that has 'error' being an object with multiple properties
        $rj->error = new stdClass();
        // Created another new stdClass object, then assign the message property there.
        // Could also be done in one line using array syntax and casting like this:
        // $rj->error = (object) ['message' => "No fish named {$delFish['name']} exists"];
        $rj->error->message = "No fish named {$delFish['name']} exists";
        return json_encode($rj);
    }
    // Be very careful here, this could delete anything in your account!
    $fishPath = DATAPATH . "/Tank/" . $delFish['name'];
    $infoPath = $fishPath . "/info";
    // Unlink will only delete a single file.
    $rc = unlink($infoPath);
    if ( $rc === false) {
        http_response_code(500);
        $rj->status = "Error deleting fish info file";
        $rj->error = error_get_last();
        return json_encode($rj);
    }
    // rmdir will delete a directory; but only if it is empty!
    $rc = rmdir($fishPath);
    if ($rc === false) {
        http_response_code(500);
        $rj->status = "Error deleting fish directory";
        $rj->error = error_get_last();
        return json_encode($rj);
    }
    $rj->status = "Fish {$delFish['name']} deleted";
    fileLog( "deleteFish succeeded for " . var_export( $delFish, true ) );
    return json_encode($rj);
}

// Read all the fish in the datastore and return as a data structure 
// We don't return as JSON here, because we use this function internally also.
function getFish($components) {
    // New array to hold whatever we read -- insures that even if no fish, it's array of size 0
    $fish = [];
    // The @ prevents warnings/errors that might go directly back to the client
    //  These would not be JSON and would cause an error when unpacking was attempted on the client.
    $fishDirs = @scandir(DATAPATH . "/Tank");
    if ($fishDirs === false) {
        $e = new stdClass();
        $e->status = "Error reading Tank directory";
        // This call gets the last php error as an associative array. See the docs for details.
        $e->error = error_get_last();
        $e->path = DATAPATH . "/Tank";
        http_response_code(500);
        return $e;
    }
    foreach ($fishDirs as $dir) {
        // Skip the special entries for current and parent directory
        if ($dir === '.' || $dir === '..') {
            continue;   
        }
        // Check your Paths and permissions carefully.
        // The info file should be a JSON file with fish details
        $infoPath = DATAPATH . "/Tank/" . $dir . "/info";
        $getDeleted = true;
        if (@file_exists($infoPath)) {
            $infoContent = file_get_contents($infoPath);
            $info = json_decode($infoContent, true); 
            if (!$getDeleted && isset($info['deleted']) && $info['deleted']) {
                continue; 
            }
            // Normalize fish to ensure all extended fields are present
            $normalizedInfo = normalizeFish($info);
            // Merge with any additional fields from the info file
            $normalizedInfo = array_merge($info, $normalizedInfo);
            $fish[$dir] = $normalizedInfo;
        } else {
            // No info file, skip Error?
            continue;   
        }
    }
    // Some additional logging for debugging and tracking that can be enabled.
    //fileLog( "getFish found " . count($fish) . " fish" );
    //fileLog( var_export( $fish, true ) );
    //fileLog( "JSON Fish: (".json_encode( $fish ).")" );

    return $fish;  
}
// Add a new fish, we are not currently using $requestComponents but might in the future
function addFish($requestComponents) {
    $newFish = (array) json_decode(file_get_contents('php://input'));
    // optional logging of input data
    fileLog( "addFish called with " . var_export( $newFish, true ) );
    // Start our blank response object here as it might be needed in various places.
    $rj = new stdClass();
    // Could also just call getFish() here to see if fish exists...
    //  Technically this is lighter weight as it doesn't read the info files.
    //  But it is less DRY... which is better??? Depends on the scale and context!
    $fishTank = @scandir(DATAPATH . "/Tank");
    if ($fishTank === false) {
        http_response_code(500);
        $rj->status = "Error reading Tank directory";
        $rj->error = error_get_last();
        $rj->path = DATAPATH . "/Tank";
        return json_encode($rj);
    }
    if (!isset($newFish['name']) || !isset($newFish['userName']) || !isset($newFish['apiKey'])) {
        http_response_code(400);
        $rj->status = "Missing required fish information";
        $rj->error = "Required fields: name, userName, apiKey";
        return json_encode($rj);
    }
    $newFish['name'] = sanitizeNameForPath($newFish['name']);
    if (isset($fishTank[$newFish['name']])) {
        http_response_code(409);
        $rj->status = "Fish name already exists";
        $rj->error = (object) ['message' => "A fish named {$newFish['name']} already exists"];
        return json_encode($rj);
    }
    // Normalize the fish to ensure extended fields are included
    $normalizedFish = normalizeFish($newFish);
    $info = [
        "name" => $normalizedFish['name'],
        "createdBy" => $newFish['userName'],
        "apiKey" => $newFish['apiKey'],
        "createdAt" => time(),
        "waterType" => $normalizedFish['waterType'],
        "aggression" => $normalizedFish['aggression'],
        "size" => $normalizedFish['size'],
        "deleted" => false
    ]; 
    $fishPath = DATAPATH . "/Tank/" . $newFish['name'];
    // Make sure the permission is such that other users can't read the data directly
    $rc = @mkdir($fishPath, 0711);
    fileLog( "addFish mkdir rc=" . var_export( $rc, true ) );
    if ($rc === false) {
        http_response_code(500);
        $rj->status = "Error adding Fish directory";
        $rj->error = error_get_last();
        $rj->path = $fishPath;
        return json_encode($rj);
    }
    // try to create the info file
    $infoPath = $fishPath . "/info";
    $rc = file_put_contents($infoPath, json_encode($info));
    if (!$rc) {
        http_response_code(500);
        $rj->status = "Error writing fish info file";
        $rj->error = error_get_last();
        $rj->path = $infoPath;
        return json_encode($rj);
    }
    $rj->status = "New fish {$newFish['name']} created";
    $rj->roomID = $newFish['name'];
    return json_encode($rj);
}

// Update a fish - new function for PUT /updateFish
function updateFish($requestComponents) {
    $updateInput = (array) json_decode(file_get_contents('php://input'));
    fileLog( "updateFish called with " . var_export( $updateInput, true ) );
    $rj = new stdClass();
    
    if (!isset($updateInput['name'])) {
        http_response_code(400);
        $rj->status = "Missing required fish name";
        $rj->error = "Required field: name";
        return json_encode($rj);
    }
    
    $updateInput['name'] = sanitizeNameForPath($updateInput['name']);
    $currentFish = getFish($requestComponents);
    
    // Check if getFish returned an error object instead of an array
    if (is_object($currentFish) && isset($currentFish->error)) {
        http_response_code(500);
        $rj->status = "Error reading fish data";
        $rj->error = $currentFish->error;
        return json_encode($rj);
    }
    
    // Can't update a fish that doesn't exist
    if (!isset($currentFish[$updateInput['name']])) {
        http_response_code(404);
        $rj->status = "Fish not found";
        $rj->error = (object) ['message' => "No fish named {$updateInput['name']} exists"];
        return json_encode($rj);
    }
    
    // Get the existing fish info and merge updates
    $existingFish = $currentFish[$updateInput['name']];
    
    // Fields that can be updated (excluding name)
    $updatableFields = ['waterType', 'aggression', 'size', 'deleted', 'createdBy', 'userName', 
                        'apiKey', 'createdAt', 'browserID', 'thumbmark'];
    
    foreach ($updatableFields as $field) {
        if (isset($updateInput[$field])) {
            $val = $updateInput[$field];
            // Skip empty string updates for text fields to prevent defaulting
            if (($field === 'waterType' || $field === 'size') && $val === "") {
                continue;
            }
            
            // Special handling for aggression to ensure it's 1-5
            if ($field === 'aggression') {
                $existingFish[$field] = max(1, min(5, intval($val)));
            } else {
                $existingFish[$field] = $val;
            }
        }
    }
    
    // Save the updated info
    // Normalize to ensure valid values and casing (e.g. saltwater -> salt)
    $normalizedFish = normalizeFish($existingFish);
    // Merge normalized values back, preserving other metadata
    $existingFish = array_merge($existingFish, $normalizedFish);
    
    $fishPath = DATAPATH . "/Tank/" . $updateInput['name'];
    $infoPath = $fishPath . "/info";
    $rc = file_put_contents($infoPath, json_encode($existingFish));
    if (!$rc) {
        http_response_code(500);
        $rj->status = "Error updating fish info file";
        $rj->error = error_get_last();
        $rj->path = $infoPath;
        return json_encode($rj);
    }
    
    // Build response with metadata - use raw input values for waterType and size
    $rj->status = "Fish {$updateInput['name']} updated";
    $rj->info = (object) [
        "name" => $existingFish['name'],
        "createdBy" => $existingFish['createdBy'] ?? $updateInput['userName'] ?? "unknown",
        "apiKey" => $existingFish['apiKey'] ?? $updateInput['apiKey'] ?? "",
        "createdAt" => $existingFish['createdAt'] ?? $updateInput['createdAt'] ?? time(),
        "waterType" => $existingFish['waterType'],
        "aggression" => $existingFish['aggression'],
        "size" => $existingFish['size'],
        "deleted" => $existingFish['deleted'] ?? false,
        "browserID" => $existingFish['browserID'] ?? $updateInput['browserID'] ?? null,
        "thumbmark" => $existingFish['thumbmark'] ?? $updateInput['thumbmark'] ?? "",
        "userName" => $existingFish['userName'] ?? $updateInput['userName'] ?? ""
    ];
    
    fileLog( "updateFish succeeded for " . var_export( $updateInput, true ) );
    return json_encode($rj);
}

function sanitizeNameForPath($name) {
    $patterns = ['/\./', '/\//']; 
    $replacements = [ '\\.', '\\\/'];
    return preg_replace($patterns, $replacements, $name);
}