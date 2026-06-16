# files_compress — compress & extract archives in Nextcloud Files

Adds two file actions to the Files app:

- **Compress** — on any selection of file(s)/folder(s): creates a single
  `zip` archive alongside them.
- **Extract here** — on an archive: unpacks it into the current folder.

**Author:** Frederik Orellana, Technical University of Denmark (fror@dtu.dk)
(NC port of the ownCloud 7 app by Lars Næsbye Christensen, DeIC)
**License:** AGPL-3.0

---

## Why shell tools instead of PHP ZipArchive

The archive work is done by the system `zip`/`unzip`/`tar`/`gzip`/`bzip2`
binaries, invoked via `proc_open` in its **array form** (no shell is spawned,
so file names cannot be turned into shell injection). Output is written
**straight to the destination** — for the single-stream `gz`/`bz2` case the
tool's stdout is wired directly to the destination file descriptor. There is
no intermediate temp file or temp directory.

This is deliberate: the earlier PHP `ZipArchive`/`PharData` implementation
zipped to a temp file and extracted to a temp directory before renaming into
place, which left large abandoned temp files behind on interrupted operations
and was slower on big datasets. Going straight to the destination avoids both.

After writing, the app runs a **targeted, recursive scan** of just the new
path so Nextcloud's file cache picks it up (the storage write API was bypassed
on purpose).

## Supported formats

| Action | Formats |
|--------|---------|
| Compress | → `zip` |
| Extract  | `zip`, `tar`, `tar.gz`/`tgz`, `tar.bz2`/`tbz`/`tbz2`, `gz`, `bz2` |

GNU `tar` auto-detects gzip/bzip2 on extract, so all `tar.*` variants go
through a single `tar -xf`. Containers (`zip`/`tar*`) unpack into a new
sub-folder named after the archive; single-stream `gz`/`bz2` produce one
decompressed file (the `.gz`/`.bz2` suffix stripped). Name collisions get a
` (n)` suffix.

## Storage support

Operations need a **local path** for the file (`getLocalFile`), which local
disk / NFS storage — including grant folders — provides. Object-store and
other path-less storages are rejected with a clear message.

## OCS API

Base: `/ocs/v2.php/apps/files_compress/api/v1` (header `OCS-APIREQUEST: true`).
Both require a logged-in session and write permission on the target folder.

| Method | Path | Params | Returns |
|--------|------|--------|---------|
| POST | `/compress` | `fileids[]` (the selection; must share one parent) | `{"name": "Foo.zip"}` |
| POST | `/extract`  | `fileid` (one archive) | `{"name": "Foo"}` (new folder, or decompressed file) |

On failure: HTTP 400 with `{"message": "…"}`.

## Frontend

`src/files-action.js` is webpack-bundled (the NC34 `registerFileAction` API is
only importable from the bundled `@nextcloud/files`, not from plain JS) to
`js/files-action.js`, which is committed. Build:

```
cd apps/files_compress
node ../user_group_admin/node_modules/webpack/bin/webpack.js --node-env production
```

The bundle is loaded after the Files app via `LoadAdditionalScriptsEvent`
(`Util::addScript('files_compress', 'files-action', 'files')`). After a
successful operation the view reloads so the new file/folder appears.

## Notes / limitations

- Compression always produces `zip` (the one format that opens everywhere).
- "Extract here" is offered for a single archive at a time.
- No app-store/sharding integration is needed — the app is fully
  self-contained and operates on whatever local storage the node holds.
