import json
import os
import re
import shutil
import sys
import tarfile
import tempfile
import threading
import traceback
import zipfile
from pathlib import Path
from tkinter import BooleanVar, Button, Checkbutton, END, Entry, Label, StringVar, Tk, filedialog, messagebox
from tkinter.scrolledtext import ScrolledText
import xml.etree.ElementTree as ET


INVALID_FILENAME_CHARS = re.compile(r'[<>:"/\\|?*]')


def log_line(log, message):
    if log:
        log(message)


def text(node, name, default=""):
    child = node.find(name)
    if child is None or child.text is None:
        return default
    return child.text


def int_text(node, name, default=0):
    try:
        return int(text(node, name, str(default)))
    except ValueError:
        return default


def safe_extract_tar(archive_path, destination):
    destination = Path(destination).resolve()

    with tarfile.open(archive_path, "r:*") as archive:
        for member in archive.getmembers():
            target = (destination / member.name).resolve()
            if destination != target and destination not in target.parents:
                raise RuntimeError(f"Unsafe archive path: {member.name}")
        archive.extractall(destination, filter="data")


def is_extracted_backup_directory(directory):
    directory = Path(directory)
    return (
        (directory / "files.xml").is_file()
        and (directory / "files").is_dir()
        and (directory / "activities").is_dir()
    )


def find_extracted_backup_directory(directory):
    directory = Path(directory)

    if is_extracted_backup_directory(directory):
        return directory.resolve()

    for path in directory.rglob("*"):
        if path.is_dir() and is_extracted_backup_directory(path):
            return path.resolve()

    return None


def find_single_nested_archive_candidate(directory):
    files = [path for path in Path(directory).rglob("*") if path.is_file()]
    if len(files) != 1:
        return None
    return files[0]


def extract_mbz(input_path, temp_dir, log=None):
    log_line(log, "Extracting MBZ backup to temporary folder...")
    safe_extract_tar(input_path, temp_dir)

    backup_dir = find_extracted_backup_directory(temp_dir)
    nested_archive = find_single_nested_archive_candidate(temp_dir)

    if backup_dir is None and nested_archive is not None:
        log_line(log, "Extracting nested archive from MBZ backup...")
        safe_extract_tar(nested_archive, temp_dir)
        backup_dir = find_extracted_backup_directory(temp_dir)

    if backup_dir is None:
        raise RuntimeError("files.xml not found in extracted MBZ backup.")

    return backup_dir


def get_stored_moodle_file_path(files_dir, contenthash):
    files_dir = Path(files_dir)
    source1 = files_dir / contenthash[:2] / contenthash
    source2 = files_dir / contenthash

    if source1.is_file():
        return source1
    if source2.is_file():
        return source2
    return None


def get_safe_output_name(name, fallback):
    safe_name = INVALID_FILENAME_CHARS.sub("_", name).strip()
    if safe_name == "":
        return fallback
    return safe_name


def get_unique_output_path(output_dir, safe_title, used_output_names):
    base_name = safe_title
    suffix = 1

    while True:
        if suffix == 1:
            filename = f"{base_name}.h5p"
        else:
            filename = f"{base_name} ({suffix}).h5p"

        key = filename.lower()
        target = Path(output_dir) / filename

        if key not in used_output_names and not target.exists():
            used_output_names.add(key)
            return target

        suffix += 1


def load_content_bank_names(backup_dir, log=None):
    content_bank_path = Path(backup_dir) / "course" / "contentbank.xml"
    if not content_bank_path.is_file():
        return {}

    try:
        root = ET.parse(content_bank_path).getroot()
    except ET.ParseError:
        log_line(log, f"Cannot parse content bank metadata: {content_bank_path}")
        return {}

    names = {}
    for content in root.findall("content"):
        content_id = content.attrib.get("id", "")
        name = text(content, "name").strip()
        if content_id and name:
            names[content_id] = name

    return names


def split_hvp_library_list(value):
    return [{"path": item.strip()} for item in value.split(",") if item.strip()]


def get_hvp_library_string(library, field):
    value = text(library, field).strip()
    if value == "$@NULL@$":
        return ""
    return value


def get_hvp_library_json_value(library, field):
    value = get_hvp_library_string(library, field)
    if value == "":
        return None

    try:
        return json.loads(value)
    except json.JSONDecodeError:
        return None


def load_hvp_library_metadata(backup_dir, log=None):
    libraries_path = Path(backup_dir) / "activities" / "hvp_libraries.xml"
    if not libraries_path.is_file():
        return {}

    try:
        root = ET.parse(libraries_path).getroot()
    except ET.ParseError:
        log_line(log, f"Cannot parse HVP library metadata: {libraries_path}")
        return {}

    libraries_by_id = {}
    metadata = {
        "jsonByFolder": {},
        "dependenciesByFolder": {},
    }

    for library in root.findall("library"):
        library_id = library.attrib.get("id", "")
        machine_name = text(library, "machine_name")
        major_version = int_text(library, "major_version")
        minor_version = int_text(library, "minor_version")

        if library_id == "" or machine_name == "":
            continue

        embed_types = [
            item.strip()
            for item in get_hvp_library_string(library, "embed_types").split(",")
            if item.strip()
        ]
        library_json = {
            "title": text(library, "title"),
            "machineName": machine_name,
            "majorVersion": major_version,
            "minorVersion": minor_version,
            "patchVersion": int_text(library, "patch_version"),
            "runnable": int_text(library, "runnable") == 1,
            "fullscreen": int_text(library, "fullscreen") == 1,
            "embedTypes": embed_types,
        }

        preloaded_js = split_hvp_library_list(get_hvp_library_string(library, "preloaded_js"))
        if preloaded_js:
            library_json["preloadedJs"] = preloaded_js

        preloaded_css = split_hvp_library_list(get_hvp_library_string(library, "preloaded_css"))
        if preloaded_css:
            library_json["preloadedCss"] = preloaded_css

        drop_library_css = get_hvp_library_string(library, "drop_library_css")
        if drop_library_css:
            library_json["dropLibraryCss"] = drop_library_css

        semantics = get_hvp_library_json_value(library, "semantics")
        if semantics is not None:
            library_json["semantics"] = semantics

        folder = f"{machine_name}-{major_version}.{minor_version}"
        dependencies_node = library.find("dependencies")
        dependencies = list(dependencies_node) if dependencies_node is not None else []

        libraries_by_id[library_id] = {
            "folder": folder,
            "machineName": machine_name,
            "majorVersion": major_version,
            "minorVersion": minor_version,
            "json": library_json,
            "dependencies": dependencies,
        }

    for library in libraries_by_id.values():
        dependency_groups = {
            "preloaded": [],
            "dynamic": [],
            "editor": [],
        }

        for dependency in library["dependencies"]:
            required_library_id = dependency.attrib.get("required_library_id", "")
            dependency_type = text(dependency, "dependency_type")

            if required_library_id not in libraries_by_id or dependency_type not in dependency_groups:
                continue

            required_library = libraries_by_id[required_library_id]
            dependency_groups[dependency_type].append(
                {
                    "machineName": required_library["machineName"],
                    "majorVersion": required_library["majorVersion"],
                    "minorVersion": required_library["minorVersion"],
                }
            )

        library_json = dict(library["json"])
        if dependency_groups["preloaded"]:
            library_json["preloadedDependencies"] = dependency_groups["preloaded"]
        if dependency_groups["dynamic"]:
            library_json["dynamicDependencies"] = dependency_groups["dynamic"]
        if dependency_groups["editor"]:
            library_json["editorDependencies"] = dependency_groups["editor"]

        folder = library["folder"]
        metadata["jsonByFolder"][folder] = library_json
        metadata["dependenciesByFolder"][folder] = {
            "preloaded": [],
            "dynamic": [],
            "editor": [],
        }

        for dependency in library["dependencies"]:
            required_library_id = dependency.attrib.get("required_library_id", "")
            dependency_type = text(dependency, "dependency_type")

            if required_library_id not in libraries_by_id:
                continue
            if dependency_type not in metadata["dependenciesByFolder"][folder]:
                continue

            metadata["dependenciesByFolder"][folder][dependency_type].append(
                libraries_by_id[required_library_id]["folder"]
            )

    return metadata


def get_hvp_library_folders_for_content(metadata, machine_name, major_version, minor_version):
    if not metadata:
        return []

    main_folder = f"{machine_name}-{major_version}.{minor_version}"
    if main_folder not in metadata["jsonByFolder"]:
        return []

    selected = set()
    stack = [main_folder]

    while stack:
        folder = stack.pop()
        if folder in selected:
            continue

        selected.add(folder)

        for dependency_type in ("preloaded", "dynamic"):
            stack.extend(metadata["dependenciesByFolder"].get(folder, {}).get(dependency_type, []))

    return list(selected)


def is_h5p_library_archive_path(internal_path):
    normalized_path = internal_path.replace("\\", "/").lstrip("/")
    first_segment = normalized_path.split("/", 1)[0]

    if first_segment in ("", "content", "h5p.json"):
        return False

    return re.match(r"^[A-Za-z0-9_.-]+-\d+\.\d+$", first_segment) is not None


def copy_h5p_package_without_libraries(source, target):
    with zipfile.ZipFile(source, "r") as source_zip:
        with zipfile.ZipFile(target, "w", compression=zipfile.ZIP_DEFLATED) as target_zip:
            for info in source_zip.infolist():
                if is_h5p_library_archive_path(info.filename):
                    continue

                if info.is_dir():
                    target_zip.mkdir(info.filename)
                    continue

                target_zip.writestr(info, source_zip.read(info.filename))


def read_files_xml(files_xml_path, backup_dir, keep_libraries, log=None):
    try:
        files_root = ET.parse(files_xml_path).getroot()
    except ET.ParseError as exc:
        raise RuntimeError(f"Cannot parse files.xml: {exc}") from exc

    media_by_item = {}
    content_bank_names = load_content_bank_names(backup_dir, log)
    hvp_library_metadata = load_hvp_library_metadata(backup_dir, log) if keep_libraries else {}
    hvp_library_files_by_folder = {}
    h5p_package_files = []
    h5p_package_hashes = {}

    for file_node in files_root.findall("file"):
        component = text(file_node, "component")
        filearea = text(file_node, "filearea")
        itemid = text(file_node, "itemid")
        filename = text(file_node, "filename")
        filepath = text(file_node, "filepath")
        contenthash = text(file_node, "contenthash")
        filesize = int_text(file_node, "filesize")

        if filename in (".", "") or filesize == 0:
            continue

        if component == "contentbank" and filearea == "public" and filename.lower().endswith(".h5p"):
            package_file = {
                "title": content_bank_names.get(itemid, Path(filename).stem),
                "fallback": f"contentbank_item_{itemid}",
                "contenthash": contenthash,
                "label": "content bank",
            }
            if contenthash in h5p_package_hashes:
                h5p_package_files[h5p_package_hashes[contenthash]] = package_file
            else:
                h5p_package_hashes[contenthash] = len(h5p_package_files)
                h5p_package_files.append(package_file)
            continue

        if component == "mod_h5pactivity" and filearea == "package" and filename.lower().endswith(".h5p"):
            if contenthash in h5p_package_hashes:
                continue
            h5p_package_hashes[contenthash] = len(h5p_package_files)
            h5p_package_files.append(
                {
                    "title": Path(filename).stem,
                    "fallback": f"h5pactivity_item_{itemid}",
                    "contenthash": contenthash,
                    "label": "H5P activity",
                }
            )
            continue

        if keep_libraries and component == "mod_hvp" and filearea == "libraries":
            internal_path = (filepath.strip("/\\") + "/" + filename).replace("\\", "/")
            library_folder = internal_path.split("/", 1)[0]

            if filename.lower() == "library.json":
                continue

            if internal_path and library_folder:
                hvp_library_files_by_folder.setdefault(library_folder, []).append(
                    {
                        "contenthash": contenthash,
                        "internalPath": internal_path,
                    }
                )
            continue

        if component != "mod_hvp" or filearea != "content":
            continue

        media_by_item.setdefault(itemid, []).append(
            {
                "filename": filename,
                "filepath": filepath,
                "contenthash": contenthash,
            }
        )

    return media_by_item, hvp_library_metadata, hvp_library_files_by_folder, h5p_package_files


def add_zip_string(zip_file, internal_path, content):
    zip_file.writestr(internal_path, content)


def add_zip_file(zip_file, source, internal_path):
    zip_file.write(source, internal_path)


def rebuild_legacy_hvp_packages(
    backup_dir,
    output_dir,
    files_dir,
    media_by_item,
    hvp_library_metadata,
    hvp_library_files_by_folder,
    keep_libraries,
    used_output_names,
    log=None,
):
    activities_dir = Path(backup_dir) / "activities"
    count = 0

    for hvp_xml_file in activities_dir.glob("hvp_*/hvp.xml"):
        try:
            activity = ET.parse(hvp_xml_file).getroot()
        except ET.ParseError:
            log_line(log, f"Skipping unreadable HVP activity XML: {hvp_xml_file}")
            continue

        hvp = activity.find("hvp")
        if hvp is None:
            continue

        itemid = hvp.attrib.get("id", "")
        title = text(hvp, "name")
        machine_name = text(hvp, "machine_name")
        major = int_text(hvp, "major_version")
        minor = int_text(hvp, "minor_version")
        json_content = text(hvp, "json_content")

        try:
            json.loads(json_content)
        except json.JSONDecodeError as exc:
            log_line(log, f"Invalid JSON for item {itemid} / {title}: {exc}")
            continue

        if itemid == "" or machine_name == "" or json_content == "":
            log_line(log, f"Skipping incomplete HVP activity: {hvp_xml_file}")
            continue

        safe_title = get_safe_output_name(title, f"hvp_item_{itemid}")
        target = get_unique_output_path(output_dir, safe_title, used_output_names)

        h5p_json = {
            "title": title,
            "language": "en",
            "mainLibrary": machine_name,
            "embedTypes": ["div"],
            "license": text(hvp, "license") or "U",
            "preloadedDependencies": [
                {
                    "machineName": machine_name,
                    "majorVersion": major,
                    "minorVersion": minor,
                }
            ],
        }

        added_media = 0
        added_library_files = 0

        with zipfile.ZipFile(target, "w", compression=zipfile.ZIP_DEFLATED) as zip_file:
            add_zip_string(
                zip_file,
                "h5p.json",
                json.dumps(h5p_json, ensure_ascii=False, indent=4),
            )
            add_zip_string(zip_file, "content/content.json", json_content)

            hvp_library_folders = (
                get_hvp_library_folders_for_content(hvp_library_metadata, machine_name, major, minor)
                if keep_libraries
                else []
            )

            for folder in hvp_library_folders:
                library_json = hvp_library_metadata["jsonByFolder"].get(folder)
                if library_json is None:
                    continue
                add_zip_string(
                    zip_file,
                    f"{folder}/library.json",
                    json.dumps(library_json, ensure_ascii=False, indent=4),
                )

            for folder in hvp_library_folders:
                for library_file in hvp_library_files_by_folder.get(folder, []):
                    source = get_stored_moodle_file_path(files_dir, library_file["contenthash"])
                    if source is None:
                        log_line(log, f"Missing library file: {library_file['contenthash']}")
                        continue
                    add_zip_file(zip_file, source, library_file["internalPath"])
                    added_library_files += 1

            for media in media_by_item.get(itemid, []):
                source = get_stored_moodle_file_path(files_dir, media["contenthash"])
                if source is None:
                    log_line(log, f"Missing media file for item {itemid}: {media['contenthash']}")
                    continue

                internal_path = "content/" + media["filepath"].lstrip("/\\") + media["filename"]
                internal_path = internal_path.replace("\\", "/")
                add_zip_file(zip_file, source, internal_path)
                added_media += 1

        if keep_libraries:
            log_line(log, f"Created OK: {target} ({added_media} media files, {added_library_files} library files)")
        else:
            log_line(log, f"Created OK: {target} ({added_media} media files)")
        count += 1

    return count


def copy_packaged_h5p_files(files_dir, output_dir, h5p_package_files, keep_libraries, used_output_names, log=None):
    count = 0

    for package_file in h5p_package_files:
        source = get_stored_moodle_file_path(files_dir, package_file["contenthash"])
        if source is None:
            log_line(log, f"Missing {package_file['label']} package file: {package_file['contenthash']}")
            continue

        safe_title = get_safe_output_name(package_file["title"], package_file["fallback"])
        target = get_unique_output_path(output_dir, safe_title, used_output_names)

        if keep_libraries:
            shutil.copyfile(source, target)
            log_line(log, f"Copied OK: {target} ({package_file['label']})")
        else:
            copy_h5p_package_without_libraries(source, target)
            log_line(log, f"Created OK: {target} ({package_file['label']}, without libraries)")

        count += 1

    return count


def extract_hvp(input_path, output_dir, keep_libraries=False, log=None):
    input_path = Path(input_path)
    output_dir = Path(output_dir)

    if not input_path.is_file() or input_path.suffix.lower() != ".mbz":
        raise RuntimeError("MBZ backup file not found.")

    output_dir.mkdir(parents=True, exist_ok=True)

    with tempfile.TemporaryDirectory(prefix="extract-hvp-") as temp_dir:
        backup_dir = extract_mbz(input_path, Path(temp_dir), log)

        files_xml_path = backup_dir / "files.xml"
        files_dir = backup_dir / "files"
        activities_dir = backup_dir / "activities"

        if not files_xml_path.is_file():
            raise RuntimeError("files.xml not found.")
        if not files_dir.is_dir():
            raise RuntimeError("files folder not found.")
        if not activities_dir.is_dir():
            raise RuntimeError("activities folder not found.")

        media_by_item, hvp_library_metadata, hvp_library_files_by_folder, h5p_package_files = read_files_xml(
            files_xml_path,
            backup_dir,
            keep_libraries,
            log,
        )

        used_output_names = set()
        count = rebuild_legacy_hvp_packages(
            backup_dir,
            output_dir,
            files_dir,
            media_by_item,
            hvp_library_metadata,
            hvp_library_files_by_folder,
            keep_libraries,
            used_output_names,
            log,
        )
        count += copy_packaged_h5p_files(
            files_dir,
            output_dir,
            h5p_package_files,
            keep_libraries,
            used_output_names,
            log,
        )

    log_line(log, f"\nDone. Extracted {count} H5P package(s).")
    return count


class ExtractHvpApp:
    def __init__(self):
        self.root = Tk()
        self.root.title("Extract HVP")
        self.root.geometry("760x570")
        self.root.minsize(640, 480)

        self.input_path = StringVar()
        self.output_path = StringVar()
        self.keep_libraries = BooleanVar(value=False)
        self.running = False

        Label(
            self.root,
            text=(
                "Extracts standalone .h5p packages from a Moodle course backup (.mbz).\n"
                "Recovers legacy mod_hvp activities and Moodle content-bank H5P packages.\n"
                "Leave libraries unchecked for smaller recovered packages; check it to keep H5P libraries inside them."
            ),
            justify="left",
            anchor="w",
            wraplength=720,
        ).grid(row=0, column=0, columnspan=2, sticky="ew", padx=12, pady=(12, 8))

        Label(self.root, text="Moodle backup (.mbz)").grid(row=1, column=0, sticky="w", padx=12, pady=(8, 4))
        Entry(self.root, textvariable=self.input_path).grid(row=2, column=0, sticky="ew", padx=12)
        Button(self.root, text="Browse...", command=self.choose_input).grid(row=2, column=1, sticky="ew", padx=(0, 12))

        Label(self.root, text="Output folder").grid(row=3, column=0, sticky="w", padx=12, pady=(12, 4))
        Entry(self.root, textvariable=self.output_path).grid(row=4, column=0, sticky="ew", padx=12)
        Button(self.root, text="Browse...", command=self.choose_output).grid(row=4, column=1, sticky="ew", padx=(0, 12))

        Checkbutton(
            self.root,
            text="Keep H5P libraries in recovered packages",
            variable=self.keep_libraries,
        ).grid(row=5, column=0, columnspan=2, sticky="w", padx=12, pady=12)

        self.run_button = Button(self.root, text="Extract H5P packages", command=self.start_extract)
        self.run_button.grid(row=6, column=0, columnspan=2, sticky="ew", padx=12)

        self.log = ScrolledText(self.root, height=14, state="disabled")
        self.log.grid(row=7, column=0, columnspan=2, sticky="nsew", padx=12, pady=12)

        self.root.columnconfigure(0, weight=1)
        self.root.rowconfigure(7, weight=1)

    def choose_input(self):
        path = filedialog.askopenfilename(
            title="Choose Moodle backup",
            filetypes=[("Moodle backups", "*.mbz"), ("All files", "*.*")],
        )
        if path:
            self.input_path.set(path)

    def choose_output(self):
        path = filedialog.askdirectory(title="Choose output folder")
        if path:
            self.output_path.set(path)

    def append_log(self, message):
        def write():
            self.log.configure(state="normal")
            self.log.insert(END, message + "\n")
            self.log.see(END)
            self.log.configure(state="disabled")

        self.root.after(0, write)

    def set_running(self, running):
        self.running = running
        self.run_button.configure(state="disabled" if running else "normal")

    def start_extract(self):
        if self.running:
            return

        input_path = self.input_path.get().strip()
        output_path = self.output_path.get().strip()

        if not input_path:
            messagebox.showerror("Missing backup", "Choose a Moodle backup .mbz file.")
            return

        if not output_path:
            messagebox.showerror("Missing output folder", "Choose an output folder.")
            return

        self.log.configure(state="normal")
        self.log.delete("1.0", END)
        self.log.configure(state="disabled")
        self.set_running(True)

        thread = threading.Thread(
            target=self.run_extract,
            args=(input_path, output_path, self.keep_libraries.get()),
            daemon=True,
        )
        thread.start()

    def run_extract(self, input_path, output_path, keep_libraries):
        try:
            count = extract_hvp(input_path, output_path, keep_libraries, self.append_log)
        except Exception:
            details = traceback.format_exc()
            self.append_log(details)
            self.root.after(0, lambda: messagebox.showerror("Extraction failed", "Extraction failed. See log for details."))
        else:
            self.root.after(0, lambda: messagebox.showinfo("Extraction complete", f"Extracted {count} H5P package(s)."))
        finally:
            self.root.after(0, lambda: self.set_running(False))

    def run(self):
        self.root.mainloop()


def main():
    if len(sys.argv) >= 3:
        keep_libraries = any(arg in ("--keeplibraries", "--keep-libraries") for arg in sys.argv[3:])
        extract_hvp(sys.argv[1], sys.argv[2], keep_libraries, print)
        return

    app = ExtractHvpApp()
    app.run()


if __name__ == "__main__":
    main()
