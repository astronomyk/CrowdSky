"""
Upload files to UCloud WebDAV share
"""

import os
import requests
from pathlib import Path
from typing import Optional


# UCloud configuration
UCLOUD_WEBDAV_URL = "https://ucloud.univie.ac.at/public.php/webdav"
UCLOUD_SHARE_TOKEN = "P3GxzHdXDixNLeW"


def upload_folder(local_folder: str, remote_folder: Optional[str] = None) -> None:
    """
    Upload a local folder to UCloud WebDAV share.
    
    Args:
        local_folder: Path to local folder to upload
        remote_folder: Name for remote folder (defaults to local folder name)
    """
    local_path = Path(local_folder)
    
    if not local_path.exists():
        raise FileNotFoundError(f"Local folder not found: {local_folder}")
    
    if not local_path.is_dir():
        raise ValueError(f"Path is not a directory: {local_folder}")
    
    # Use local folder name if remote name not specified
    if remote_folder is None:
        remote_folder = local_path.name
    
    # WebDAV authentication (token as username, empty password)
    auth = (UCLOUD_SHARE_TOKEN, "")
    
    # Create remote folder
    folder_url = f"{UCLOUD_WEBDAV_URL}/{remote_folder}"
    print(f"📁 Creating remote folder: {remote_folder}")
    
    try:
        response = requests.request("MKCOL", folder_url, auth=auth)
        if response.status_code == 201:
            print(f"✓ Folder created")
        elif response.status_code == 405:
            print(f"✓ Folder already exists")
        else:
            print(f"⚠ Unexpected response: {response.status_code}")
    except requests.exceptions.RequestException as e:
        print(f"❌ Failed to create folder: {e}")
        raise
    
    # Get all files in local folder
    files = [f for f in local_path.iterdir() if f.is_file()]
    total_files = len(files)
    total_size = sum(f.stat().st_size for f in files)
    
    print(f"📤 Uploading {total_files} files ({total_size / 1024 / 1024:.1f} MB)...")
    
    # Upload each file
    uploaded = 0
    failed = 0
    
    for idx, file_path in enumerate(files, 1):
        file_url = f"{UCLOUD_WEBDAV_URL}/{remote_folder}/{file_path.name}"
        file_size_mb = file_path.stat().st_size / 1024 / 1024
        
        print(f"  [{idx}/{total_files}] {file_path.name} ({file_size_mb:.2f} MB)", end="", flush=True)
        
        try:
            with open(file_path, "rb") as f:
                response = requests.put(file_url, data=f, auth=auth)
            
            if response.status_code in (200, 201, 204):
                print(" ✓")
                uploaded += 1
            else:
                print(f" ❌ (HTTP {response.status_code})")
                failed += 1
        except Exception as e:
            print(f" ❌ ({e})")
            failed += 1
    
    print(f"\n{'='*60}")
    print(f"✓ Upload complete: {uploaded} succeeded, {failed} failed")
    print(f"📊 Total uploaded: {sum(f.stat().st_size for f in files[:uploaded]) / 1024 / 1024:.1f} MB")
    

def upload_file(local_file: str, remote_path: str) -> bool:
    """
    Upload a single file to UCloud WebDAV share.
    
    Args:
        local_file: Path to local file
        remote_path: Remote path (e.g., "folder/file.fits")
    
    Returns:
        True if successful, False otherwise
    """
    local_path = Path(local_file)
    
    if not local_path.exists():
        raise FileNotFoundError(f"Local file not found: {local_file}")
    
    auth = (UCLOUD_SHARE_TOKEN, "")
    file_url = f"{UCLOUD_WEBDAV_URL}/{remote_path}"
    
    try:
        with open(local_path, "rb") as f:
            response = requests.put(file_url, data=f, auth=auth)
        
        return response.status_code in (200, 201, 204)
    except Exception as e:
        print(f"❌ Upload failed: {e}")
        return False


# Example usage
if __name__ == "__main__":
    import sys
    
    if len(sys.argv) < 2:
        print("Usage: python upload.py <folder_path> [remote_folder_name]")
        print("\nExamples:")
        print("  python ucloud_upload.py ./Corot_1_sub")
        print("  python ucloud_upload.py ./Corot_1_sub CustomFolderName")
        sys.exit(1)
    
    local_folder = sys.argv[1]
    remote_folder = sys.argv[2] if len(sys.argv) > 2 else None
    
    upload_folder(local_folder, remote_folder)
