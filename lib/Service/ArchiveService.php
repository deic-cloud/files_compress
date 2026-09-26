<?php

declare(strict_types=1);

namespace OCA\FilesCompress\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Compress and extract archives by shelling out to the system zip/unzip/tar/
 * gzip/bzip2 tools, operating directly on the storage's local paths and
 * writing straight to the destination — no intermediate temp files. After a
 * write we trigger a (cheap, targeted) scan so Nextcloud's cache sees the
 * result, since we bypassed the storage write API on purpose.
 *
 * Works for any storage that exposes a local path (local disk / NFS, incl.
 * grant folders); other storages (object store) are rejected up front.
 */
class ArchiveService {
	/** PATH for the spawned tools (containers vary). */
	private const ENV = ['PATH' => '/usr/local/bin:/usr/bin:/bin'];

	public function __construct(
		private IRootFolder $rootFolder,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Compress the given nodes (all sharing one parent) into a single zip in
	 * that parent folder.
	 *
	 * @param int[] $fileids
	 * @return array{name: string}
	 */
	public function compress(string $uid, array $fileids): array {
		@set_time_limit(0);
		$nodes = $this->resolveNodes($uid, $fileids);
		if (empty($nodes)) {
			throw new ArchiveException($this->l->t('Nothing selected to compress.'));
		}
		$parent = $nodes[0]->getParent();
		foreach ($nodes as $n) {
			if ($n->getParent()->getId() !== $parent->getId()) {
				throw new ArchiveException($this->l->t('All items must be in the same folder.'));
			}
		}
		if (!$parent->isCreatable()) {
			throw new ArchiveException($this->l->t('You do not have permission to write to this folder.'));
		}
		$parentLocal = $this->localPath($parent);

		$destName = $this->uniqueName(
			$parent,
			count($nodes) === 1
				? $nodes[0]->getName() . '.zip'
				: ($parent->getName() !== '' ? $parent->getName() : 'Archive') . '.zip',
		);

		// Relative names + a cwd of the parent so the archive stores clean,
		// relative paths. Array form of proc_open => no shell => no injection.
		// zip has no "--" end-of-options marker, so prefix every name with
		// "./" to neutralise any leading-dash file names instead.
		$args = ['zip', '-r', '-q', './' . $destName];
		foreach ($nodes as $n) {
			$args[] = './' . $n->getName();
		}
		[$code, , $err] = $this->run($args, $parentLocal);
		if ($code !== 0) {
			$this->logger->error('files_compress: zip exited ' . $code . ': ' . $err, ['app' => 'files_compress']);
			throw new ArchiveException($this->l->t('Compression failed.'));
		}

		$this->rescan($parent, $destName);
		return ['name' => $destName];
	}

	/**
	 * Extract one archive into its parent folder. Containers (zip/tar/…)
	 * unpack into a new sub-folder named after the archive; single-stream
	 * gz/bz2 produce a single decompressed file.
	 *
	 * @return array{name: string}
	 */
	public function extract(string $uid, int $fileid): array {
		@set_time_limit(0);
		$nodes = $this->resolveNodes($uid, [$fileid]);
		if (empty($nodes)) {
			throw new ArchiveException($this->l->t('Archive not found.'));
		}
		$node = $nodes[0];
		$parent = $node->getParent();
		if (!$parent->isCreatable()) {
			throw new ArchiveException($this->l->t('You do not have permission to write to this folder.'));
		}
		$parentLocal = $this->localPath($parent);
		$archiveLocal = $this->localPath($node);
		$lower = strtolower($node->getName());

		// tar (and its compressed variants) — GNU tar auto-detects gzip/bzip2
		// on extract, so a single `tar -xf` covers .tar/.tar.gz/.tgz/.tar.bz2.
		$tarSuffixes = ['.tar', '.tar.gz', '.tgz', '.tar.bz2', '.tbz', '.tbz2'];
		$isTar = $this->endsWithAny($lower, $tarSuffixes);

		if ($lower !== '' && (str_ends_with($lower, '.zip') || $isTar)) {
			$destName = $this->uniqueName($parent, $this->stripArchiveExt($node->getName()));
			$destLocal = $parentLocal . '/' . $destName;
			if (!@mkdir($destLocal) && !is_dir($destLocal)) {
				throw new ArchiveException($this->l->t('Could not create the destination folder.'));
			}
			$args = str_ends_with($lower, '.zip')
				? ['unzip', '-o', '-q', $archiveLocal, '-d', $destLocal]
				: ['tar', '-x', '-f', $archiveLocal, '-C', $destLocal];
			[$code, , $err] = $this->run($args, null);
			if ($code !== 0) {
				$this->logger->error('files_compress: extract exited ' . $code . ': ' . $err, ['app' => 'files_compress']);
				throw new ArchiveException($this->l->t('Extraction failed.'));
			}
		} elseif (str_ends_with($lower, '.gz') || str_ends_with($lower, '.bz2')) {
			// Single decompressed stream → one file. Strip the .gz/.bz2 suffix;
			// stdout is wired straight to the destination file (no temp).
			$base = preg_replace('/\.(gz|bz2)$/i', '', $node->getName());
			if ($base === '' || $base === $node->getName()) {
				$base = $node->getName() . '.out';
			}
			$destName = $this->uniqueName($parent, $base);
			$destLocal = $parentLocal . '/' . $destName;
			$tool = str_ends_with($lower, '.gz') ? 'gzip' : 'bzip2';
			[$code, , $err] = $this->runToFile([$tool, '-d', '-c', '--', $archiveLocal], $destLocal);
			if ($code !== 0) {
				@unlink($destLocal);
				$this->logger->error('files_compress: ' . $tool . ' exited ' . $code . ': ' . $err, ['app' => 'files_compress']);
				throw new ArchiveException($this->l->t('Extraction failed.'));
			}
		} else {
			throw new ArchiveException($this->l->t('Unsupported archive format.'));
		}

		$this->rescan($parent, $destName);
		return ['name' => $destName];
	}

	// --- helpers -------------------------------------------------------------

	/**
	 * @param int[] $fileids
	 * @return Node[]
	 */
	private function resolveNodes(string $uid, array $fileids): array {
		if ($uid === '') {
			throw new ArchiveException($this->l->t('Not logged in.'));
		}
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$nodes = [];
		foreach ($fileids as $id) {
			$id = (int)$id;
			if ($id <= 0) {
				continue;
			}
			$found = $userFolder->getById($id);
			if (!empty($found)) {
				$nodes[] = $found[0];
			}
		}
		return $nodes;
	}

	private function localPath(Node $node): string {
		$local = $node->getStorage()->getLocalFile($node->getInternalPath());
		if (!is_string($local) || $local === '') {
			throw new ArchiveException($this->l->t('Archive operations are not supported on this storage.'));
		}
		return rtrim($local, '/');
	}

	/** Find a name not yet taken in $parent, inserting " (n)" before the extension. */
	private function uniqueName(Folder $parent, string $name): string {
		if (!$parent->nodeExists($name)) {
			return $name;
		}
		$dot = strpos($name, '.');
		$stem = $dot === false ? $name : substr($name, 0, $dot);
		$ext = $dot === false ? '' : substr($name, $dot);
		for ($i = 1; $i < 1000; $i++) {
			$candidate = $stem . ' (' . $i . ')' . $ext;
			if (!$parent->nodeExists($candidate)) {
				return $candidate;
			}
		}
		throw new ArchiveException($this->l->t('Could not find an available name.'));
	}

	/** Strip a known archive suffix to get the base (folder) name. */
	private function stripArchiveExt(string $name): string {
		$base = preg_replace('/\.(zip|tar\.gz|tgz|tar\.bz2|tbz2|tbz|tar)$/i', '', $name);
		return ($base === '' || $base === null) ? ($name . '.extracted') : $base;
	}

	private function endsWithAny(string $haystack, array $suffixes): bool {
		foreach ($suffixes as $s) {
			if (str_ends_with($haystack, $s)) {
				return true;
			}
		}
		return false;
	}

	/** Targeted, recursive scan of the new path so the cache picks it up. */
	private function rescan(Folder $parent, string $name): void {
		$internal = $parent->getInternalPath();
		$path = ($internal === '' ? '' : $internal . '/') . $name;
		try {
			$parent->getStorage()->getScanner()->scan($path);
		} catch (\Throwable $e) {
			$this->logger->warning('files_compress: scan of ' . $path . ' failed: ' . $e->getMessage(), ['app' => 'files_compress']);
		}
	}

	/**
	 * The program by its full path, found in self::ENV's PATH. For the array
	 * form, proc_open() looks a bare name up in the WEB SERVER's own PATH, not in
	 * the environment it passes on — Apache on FreeBSD has no /usr/local/bin,
	 * where packages such as zip live, so 'zip' failed with "posix_spawn()
	 * failed: No such file or directory" although it was installed.
	 *
	 * @param string[] $cmd
	 * @return string[]
	 */
	private function resolveProgram(array $cmd): array {
		if ($cmd === [] || str_contains($cmd[0], '/')) {
			return $cmd;
		}
		foreach (explode(':', self::ENV['PATH']) as $dir) {
			$candidate = rtrim($dir, '/') . '/' . $cmd[0];
			if ($dir !== '' && is_file($candidate) && is_executable($candidate)) {
				$cmd[0] = $candidate;
				return $cmd;
			}
		}
		throw new ArchiveException($this->l->t('The archive tool "%s" is not installed on this server.', [$cmd[0]]));
	}

	/**
	 * Run a command (array form => no shell). Returns [exitCode, stdout, stderr].
	 *
	 * @param string[] $cmd
	 * @return array{0: int, 1: string, 2: string}
	 */
	private function run(array $cmd, ?string $cwd): array {
		$cmd = $this->resolveProgram($cmd);
		$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$proc = proc_open($cmd, $desc, $pipes, $cwd, self::ENV);
		if (!is_resource($proc)) {
			throw new ArchiveException($this->l->t('Could not start the archive tool.'));
		}
		fclose($pipes[0]);
		$out = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		$code = proc_close($proc);
		return [$code, (string)$out, (string)$err];
	}

	/**
	 * Like run(), but pipes the command's stdout straight into $destLocal
	 * (used for single-stream gz/bz2 decompression — no temp, no buffering).
	 *
	 * @param string[] $cmd
	 * @return array{0: int, 1: string, 2: string}
	 */
	private function runToFile(array $cmd, string $destLocal): array {
		$cmd = $this->resolveProgram($cmd);
		$desc = [0 => ['pipe', 'r'], 1 => ['file', $destLocal, 'w'], 2 => ['pipe', 'w']];
		$proc = proc_open($cmd, $desc, $pipes, null, self::ENV);
		if (!is_resource($proc)) {
			throw new ArchiveException($this->l->t('Could not start the archive tool.'));
		}
		fclose($pipes[0]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		$code = proc_close($proc);
		return [$code, '', (string)$err];
	}
}
