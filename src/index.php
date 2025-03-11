<?php

class index
{

    public $route;
    public $path;
    public $base64Content;

    public function __construct()
    {
        $this->route = $_GET["route"] ?? "";
        $data = json_decode(file_get_contents("php://input"), true);
        $this->path = $data["path"] ?? "";
        $this->base64Content = $data["base64Content"] ?? "";
    }

    public function __toString()
    {
        return $this->handleResponse($this->runScript($this->route));
    }

    private function handleResponse(mixed $return): string
    {
        if (is_array($return)) {
            return json_encode($return);
        } else {
            return (string) $return;
        }
    }

    private function runScript(string $route): mixed
    {
        switch ($route) {
            case "verify":
                return $this->verifyAndFixStorage();
            case "upload":
                return $this->setFileBase64($this->path, $this->base64Content);
            case "download":
                return $this->getFileBase64($this->path);
            default:
                return ["error" => "Rota não encontrada"];
        }
    }

    private function getAvailableDisks()
    {
        $storagePath = "/storage"; // Diretório base do storage
        $disks = [];

        if (is_dir($storagePath)) {
            foreach (scandir($storagePath) as $disk) {
                if ($disk !== "." && $disk !== "..") {
                    $diskPath = $storagePath . "/" . $disk;
                    if (is_dir($diskPath) && is_writable($diskPath)) {
                        $disks[] = $diskPath;
                    }
                }
            }
        }

        return $disks;
    }

    public function setFileBase64($filePath, $base64Content)
    {
        $disks = $this->getAvailableDisks();
        if (count($disks) < 2) {
            return ["error" => "É necessário pelo menos dois discos disponíveis"];
        }

        // Seleciona os dois discos com mais espaço disponível
        usort($disks, function ($a, $b) {
            return disk_free_space($b) <=> disk_free_space($a);
        });

        $primaryDisk = $disks[0];
        $secondaryDisk = $disks[1];

        $absolutePath1 = rtrim($primaryDisk, "/") . "/" . ltrim($filePath, "/");
        $absolutePath2 = rtrim($secondaryDisk, "/") . "/" . ltrim($filePath, "/");

        foreach ([$absolutePath1, $absolutePath2] as $path) {
            $directory = dirname($path);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            $decodedFile = base64_decode($base64Content);
            if ($decodedFile === false) {
                return ["error" => "Falha ao decodificar Base64"];
            }

            if (file_put_contents($path, $decodedFile) === false) {
                return ["error" => "Erro ao salvar o arquivo"];
            }
        }

        return ["success" => true];
    }

    function getFileBase64($filePath)
    {
        $disks = $this->getAvailableDisks();

        foreach ($disks as $disk) {
            $absolutePath = rtrim($disk, '/') . '/' . ltrim($filePath, '/');

            if (file_exists($absolutePath)) {
                $fileContent = file_get_contents($absolutePath);
                $base64Content = base64_encode($fileContent);

                return [
                    'success' => true,
                    'filename' => basename($filePath),
                    'mime_type' => mime_content_type($absolutePath),
                    'base64' => $base64Content
                ];
            }
        }

        return ['error' => 'Arquivo não encontrado'];
    }


    public function verifyAndFixStorage(): array
    {
        $disks = $this->getAvailableDisks();
        $fileLocations = [];

        // Mapeia onde cada arquivo está
        foreach ($disks as $disk) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($disk, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $relativePath = str_replace($disk . '/', '', $file->getPathname());
                $fileLocations[$relativePath][] = $disk;
            }
        }

        // Ajusta arquivos que estão em apenas um ou mais de dois discos
        foreach ($fileLocations as $file => $locations) {
            if (count($locations) == 1) {
                // Arquivo só existe em um disco → Copiar para outro disco
                $sourceDisk = $locations[0];
                $targetDisks = array_diff($disks, [$sourceDisk]);

                // Escolher o disco com mais espaço
                usort($targetDisks, function ($a, $b) {
                    return disk_free_space($b) <=> disk_free_space($a);
                });

                if (!empty($targetDisks)) {
                    $newDisk = $targetDisks[0];
                    $sourcePath = $sourceDisk . '/' . $file;
                    $targetPath = $newDisk . '/' . $file;

                    if (!is_dir(dirname($targetPath))) {
                        mkdir(dirname($targetPath), 0777, true);
                    }

                    copy($sourcePath, $targetPath);
                }
            } elseif (count($locations) > 2) {
                // Arquivo existe em mais de dois discos → Remover cópias extras
                while (count($locations) > 2) {
                    $diskToRemove = array_pop($locations);
                    unlink($diskToRemove . '/' . $file);
                }
            }
        }

        return ["success" => true];
    }
}

echo new index();
