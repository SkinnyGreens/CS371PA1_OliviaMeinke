#!/usr/bin/env python3
import os
import sys
import json
import time
import re
import cgitb
cgitb.enable()

# Disable error reporting to output (similar to php.ini settings)
# sys.stderr = open('/dev/null', 'w')

# Constants
DATAPATH = os.path.dirname(os.path.abspath(__file__)) + "/"
LOGPATH = os.path.dirname(os.path.abspath(__file__)) + "/API.log"

def file_log(message):
    timestamp = time.strftime("%Y-%m-%d %H:%M:%S")
    log_entry = f"[{timestamp}] {message}\n"
    try:
        with open(LOGPATH, "a") as f:
            f.write(log_entry)
    except Exception:
        pass # Ignore logging errors

def sanitize_name_for_path(name):
    # PHP: preg_replace(['/\./', '/\//'], [ '\\.', '\\\/'], $name);
    # Actually, in PHP version it was replacing . with \. and / with \/
    # But usually sanitation means REMOVING or replacing dangerous chars.
    # The PHP version was weird, but I'll stick to a safe basic sanitation for Python
    # just removing . and / to avoid path traversal.
    return re.sub(r'[\./]', '', name)

def normalize_fish(fish):
    # Ensure aggression is a number between 1-5
    try:
        aggression = int(fish.get('aggression', 3))
    except ValueError:
        aggression = 3
        
    if aggression < 1 or aggression > 5:
        aggression = 3

    # Validate size
    valid_sizes = ['small', 'medium', 'large', 'extra-large']
    size = str(fish.get('size', 'small')).strip()
    
    # Map common variations
    if size.lower() in ['extra large', 'extra_large']:
        size = 'extra-large'
    
    size_found = False
    for valid_size in valid_sizes:
        if size.lower() == valid_size.lower():
            size = valid_size
            size_found = True
            break
    
    if not size_found:
        size = 'small'

    # Validate waterType
    valid_water_types = ['fresh', 'salt', 'brackish']
    water_type = str(fish.get('waterType', 'fresh')).strip()
    
    # Map variations
    if water_type.lower() in ['saltwater', 'salt water']:
        water_type = 'salt'
    elif water_type.lower() in ['freshwater', 'fresh water']:
        water_type = 'fresh'
        
    water_type_found = False
    for valid_type in valid_water_types:
        if water_type.lower() == valid_type.lower():
            water_type = valid_type
            water_type_found = True
            break
            
    if not water_type_found:
        water_type = 'fresh'

    return {
        'name': fish.get('name', ''),
        'waterType': water_type,
        'aggression': aggression,
        'size': size
    }

def get_fish(components=None):
    fish_tank_path = os.path.join(DATAPATH, "Tank")
    fish_data = {}
    
    if not os.path.exists(fish_tank_path):
        # Return error structure as dict to be handled by caller
        return {
            "status": "Error reading Tank directory", 
            "error": {"message": "Tank directory not found"},
            "path": fish_tank_path,
            "_is_error": True
        }

    try:
        with os.scandir(fish_tank_path) as it:
            for entry in it:
                if entry.name.startswith('.') or not entry.is_dir():
                    continue
                
                info_path = os.path.join(entry.path, "info")
                if os.path.exists(info_path):
                    try:
                        with open(info_path, 'r') as f:
                            info = json.load(f)
                            
                        if info.get('deleted'):
                            continue
                            
                        # Normalize and merge
                        normalized = normalize_fish(info)
                        # Merge: Python update (merges in place)
                        # info.update(normalized) -> normalized overrides info
                        # We want normalized keys to override info keys but keep extra info keys
                        merged = info.copy()
                        merged.update(normalized)
                        fish_data[entry.name] = merged
                    except json.JSONDecodeError:
                        continue
    except OSError as e:
        return {
            "status": "Error reading Tank directory",
            "error": {"message": str(e)},
            "path": fish_tank_path,
            "_is_error": True
        }
        
    return fish_data

def add_fish(request_components, input_data):
    file_log(f"addFish called with {input_data}")
    
    # Check Tank dir
    fish_tank_path = os.path.join(DATAPATH, "Tank")
    if not os.path.exists(fish_tank_path):
        return {
            "status": "Error reading Tank directory",
            "error": {"message": "Directory not found"},
            "path": fish_tank_path
        }, 500

    if not all(k in input_data for k in ('name', 'userName', 'apiKey')):
        return {
            "status": "Missing required fish information",
            "error": "Required fields: name, userName, apiKey"
        }, 400

    safe_name = sanitize_name_for_path(input_data['name'])
    input_data['name'] = safe_name
    
    fish_path = os.path.join(fish_tank_path, safe_name)
    if os.path.exists(fish_path):
        return {
            "status": "Error adding Fish directory",
            "error": {
                "type": 2,
                "message": "mkdir(): File exists",
                "file": __file__,
                "line": 0 # Placeholder
            },
            "path": fish_path
        }, 409

    normalized = normalize_fish(input_data)
    
    info = {
        "name": normalized['name'],
        "createdBy": input_data['userName'],
        "apiKey": input_data['apiKey'],
        "createdAt": int(time.time()),
        "waterType": normalized['waterType'],
        "aggression": normalized['aggression'],
        "size": normalized['size'],
        "deleted": False
    }

    try:
        os.mkdir(fish_path, 0o711)
        with open(os.path.join(fish_path, "info"), 'w') as f:
            json.dump(info, f)
    except OSError as e:
        return {
            "status": "Error creating fish",
            "error": str(e),
            "path": fish_path
        }, 500

    return {
        "status": f"New fish {safe_name} created",
        "roomID": safe_name
    }, 201

def update_fish(request_components, input_data):
    file_log(f"updateFish called with {input_data}")
    
    if 'name' not in input_data:
        return {
            "status": "Missing required fish name",
            "error": "Required field: name"
        }, 400

    safe_name = sanitize_name_for_path(input_data['name'])
    input_data['name'] = safe_name
    
    # Get current fish
    current_fish_data = get_fish(request_components)
    if "_is_error" in current_fish_data:
        return current_fish_data, 500

    if safe_name not in current_fish_data:
        return {
            "status": "Fish not found",
            "error": {"message": f"No fish named {safe_name} exists"}
        }, 404

    existing_fish = current_fish_data[safe_name]
    
    updatable_fields = ['waterType', 'aggression', 'size', 'deleted', 'createdBy', 
                        'userName', 'apiKey', 'createdAt', 'browserID', 'thumbmark']

    for field in updatable_fields:
        if field in input_data:
            val = input_data[field]
            # Skip empty strings for text fields
            if field in ['waterType', 'size'] and val == "":
                continue
                
            if field == 'aggression':
                try:
                    val = int(val)
                    existing_fish[field] = max(1, min(5, val))
                except ValueError:
                    pass # Keep existing if invalid
            else:
                existing_fish[field] = val

    # Normalize and merge
    normalized = normalize_fish(existing_fish)
    existing_fish.update(normalized)

    # Save
    fish_path = os.path.join(DATAPATH, "Tank", safe_name)
    info_path = os.path.join(fish_path, "info")
    
    try:
        with open(info_path, 'w') as f:
            json.dump(existing_fish, f)
    except OSError as e:
        return {
            "status": "Error updating fish info file",
            "error": str(e),
            "path": info_path
        }, 500

    response = {
        "status": f"Fish {safe_name} updated",
        "info": {
            "name": existing_fish['name'],
            "createdBy": existing_fish.get('createdBy') or input_data.get('userName') or "unknown",
            "apiKey": existing_fish.get('apiKey') or input_data.get('apiKey') or "",
            "createdAt": existing_fish.get('createdAt') or input_data.get('createdAt') or int(time.time()),
            "waterType": existing_fish['waterType'],
            "aggression": existing_fish['aggression'],
            "size": existing_fish['size'],
            "deleted": existing_fish.get('deleted', False),
            "browserID": existing_fish.get('browserID') or input_data.get('browserID'),
            "thumbmark": existing_fish.get('thumbmark') or input_data.get('thumbmark') or "",
            "userName": existing_fish.get('userName') or input_data.get('userName') or ""
        }
    }
    file_log(f"updateFish succeeded for {input_data}")
    return response, 200

def delete_fish(request_components, input_data):
    file_log(f"deleteFish called with {input_data}")
    
    safe_name = sanitize_name_for_path(input_data.get('name', ''))
    
    current_fish_data = get_fish(request_components)
    if "_is_error" in current_fish_data:
        return current_fish_data, 500

    if safe_name not in current_fish_data:
        return {
            "status": "Fish not found",
            "error": {"message": f"No fish named {safe_name} exists"}
        }, 404

    fish_path = os.path.join(DATAPATH, "Tank", safe_name)
    info_path = os.path.join(fish_path, "info")
    
    try:
        if os.path.exists(info_path):
            os.remove(info_path)
        if os.path.exists(fish_path):
            os.rmdir(fish_path)
    except OSError as e:
        return {
            "status": "Error deleting fish",
            "error": str(e)
        }, 500

    file_log(f"deleteFish succeeded for {input_data}")
    return {"status": f"Fish {safe_name} deleted"}, 200

def noop(request_components, input_data, method):
    file_log(f"noop called with {input_data}")
    return {
        "status": "noop",
        "method": method,
        "rc": request_components,
        "input": input_data
    }, 200

# Main execution
def main():
    # Print Headers
    print("Access-Control-Allow-Origin: *")
    print("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE")
    print("Access-Control-Allow-Headers: Origin,Accept, X-Requested-With, Content-Type, Access-Control-Request-Method, Access-Control-Request-Headers")
    print("Access-Control-Allow-Credentials: true")
    print("Content-Type: application/json")
    print() # End of headers

    method = os.environ.get('REQUEST_METHOD', 'GET')
    request_uri = os.environ.get('REQUEST_URI', '')
    
    # Parse URL
    # Similar to PHP logic: explode path
    # Remove query string
    path = request_uri.split('?')[0]
    path_parts = [p for p in path.split('/') if p]
    
    # Last part is action? 
    # PHP logic: $requestAction = explode('?',$requestArray[count($requestArray) - 1 ])[0];
    # But wait, earlier PHP logic also used $requestComponents = array_slice($requestArray, 2);
    # Assuming standard path structure: /.../index.python/action/component...
    
    # Let's try to find 'index.python' index and go from there, or take last part
    action = path_parts[-1] if path_parts else ''
    components = [] # Simplification
    
    # Read input
    input_data = {}
    try:
        if method in ['POST', 'PUT', 'DELETE']:
            # Read from stdin
            content_len = int(os.environ.get('CONTENT_LENGTH', 0))
            if content_len > 0:
                body = sys.stdin.read(content_len)
                input_data = json.loads(body)
    except Exception:
        pass

    status_code = 200
    response = {}

    try:
        if method == 'OPTIONS':
            status_code = 200
            response = {} # Empty body
        
        elif method == 'POST':
            if action == 'addFish':
                response, status_code = add_fish(components, input_data)
            elif action == 'noop':
                response, status_code = noop(components, input_data, method)
            else:
                status_code = 404
                
        elif method == 'GET':
            if action == 'getFish':
                data = get_fish(components)
                if "_is_error" in data:
                    response = data
                    status_code = 500
                else:
                    response = data
            elif action == 'noop':
                response, status_code = noop(components, input_data, method)
            else:
                status_code = 409
                
        elif method == 'PUT':
            if action == 'updateFish':
                response, status_code = update_fish(components, input_data)
            elif action == 'noop':
                response, status_code = noop(components, input_data, method)
            else:
                status_code = 404
                
        elif method == 'DELETE':
            if action == 'deleteFish':
                response, status_code = delete_fish(components, input_data)
            elif action == 'noop':
                response, status_code = noop(components, input_data, method)
            else:
                status_code = 404
        else:
            status_code = 404

    except Exception as e:
        status_code = 500
        response = {"error": str(e)}

    # Log status
    file_log(f"{method} {action} - {status_code}")
    
    # Output JSON
    if response or response == {}:
        print(json.dumps(response, indent=4))

if __name__ == "__main__":
    main()

