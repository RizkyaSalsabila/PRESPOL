<?php 
session_start();
include_once 'config/Database.php';
include_once 'classes/Auth.php';
Auth::checkLogin();

class InputProses {
    private $db;
    private $uploadDir = "upload/";
    private $fileRules = [
        'foto_kompetisi' => ['allowed' => ['jpg', 'jpeg', 'png', 'gif'], 'size' => 5 * 1024 * 1024],
        'flyer' => ['allowed' => ['jpg', 'jpeg', 'png', 'gif', 'pdf'], 'size' => 5 * 1024 * 1024],
        'sertifikat' => ['allowed' => ['pdf'], 'size' => 5 * 1024 * 1024],
        'surat_tugas' => ['allowed' => ['pdf'], 'size' => 5 * 1024 * 1024],
        'karya_kompetisi' => ['allowed' => [], 'size' => 5 * 1024 * 1024], // Semua jenis file
    ];

    public function __construct(Database $db) {
        $this->db = $db;
        $this->prepareUploadDir();
    }

    private function prepareUploadDir() {
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    public function sanitizeInput($data) {
        $trimmed = trim($data);
        return $trimmed === '' ? null : htmlspecialchars($trimmed);
    }

    public function uploadFile($file, $allowedExtensions, $maxSize, $key) {
        // Periksa apakah ada kesalahan dalam proses upload file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Terjadi kesalahan saat mengunggah file: " . $file['name']);
        }

        // Periksa ukuran file, pastikan tidak melebihi batas
        if ($file['size'] > $maxSize) {
            throw new Exception("Ukuran file {$file['name']} melebihi batas maksimum 5 MB.");
        }

        // Periksa ekstensi file
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!empty($allowedExtensions) && !in_array($extension, $allowedExtensions)) {
            throw new Exception("File {$file['name']} memiliki ekstensi yang tidak diizinkan.");
        }

        // Cek MIME type file untuk memastikan jenis file yang benar
        $mimeType = mime_content_type($file['tmp_name']);
        if (!in_array($mimeType, $this->getAllowedMimeTypes($extension))) {
            throw new Exception("File {$file['name']} memiliki MIME type yang tidak sesuai.");
        }

        // Map untuk subdirektori berdasarkan key file
        $subDirMap = [
            'foto_kompetisi' => 'upload/prestasi/kompetisi/',
            'flyer' => 'upload/prestasi/flyer/',
            'sertifikat' => 'upload/prestasi/sertifikat/',
            'surat_tugas' => 'upload/prestasi/surat-tugas/',
            'karya_kompetisi' => 'upload/prestasi/karya/',
        ];

        // Validasi key file
        if (!isset($subDirMap[$key])) {
            throw new Exception("Key file tidak valid: {$key}");
        }

        $subDir = $subDirMap[$key];

        $fileName = strtolower(uniqid('file_', true) . "_" . basename($file['name']));
        
        $fileName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $fileName);

        $filePath = $subDir . $fileName;

        $counter = 1;
        while (file_exists($filePath)) {
            $fileName = strtolower(uniqid('file_' . $counter . '_', true) . "_" . basename($file['name']));
            $filePath = $subDir . $fileName;
            $counter++;
        }

        // Pastikan direktori tujuan ada, jika belum buat
        if (!is_dir($subDir)) {
            mkdir($subDir, 0755, true);
        }

        // Pindahkan file ke direktori tujuan
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            throw new Exception("Gagal memindahkan file {$file['name']} ke direktori tujuan.");
        }

        return $filePath;
    }

    // Mendapatkan MIME type yang diizinkan berdasarkan ekstensi file
    private function getAllowedMimeTypes($extension) {
        $mimeTypes = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'pdf' => ['application/pdf'],
            // Tambahkan lebih banyak ekstensi sesuai kebutuhan
        ];

        return $mimeTypes[$extension] ?? [];
    }

    public function processForm($formData, $fileData) {
        try {
            $sql = "SELECT id_mahasiswa FROM mahasiswa WHERE nim = ?";
            $params = [$formData['nim']]; 
            $id_mahasiswa = $this->db->fetchOne($sql, $params);
            
            if (!$id_mahasiswa) {
                throw new Exception("Mahasiswa dengan NIM {$formData['nim']} tidak ditemukan.");
            }

            // Menyaring dan menyiapkan data dari form
            $data = [
                'id_mahasiswa' => $id_mahasiswa['id_mahasiswa'],
                'nama_kompetisi' => $this->sanitizeInput($formData['nama_kompetisi']),
                'id_juara' => (int)$this->sanitizeInput($formData['id_juara']),
                'penyelenggara' => $this->sanitizeInput($formData['penyelenggara']),
                'event' => $this->sanitizeInput($formData['event']),
                'dosen_pembimbing_1' => $this->sanitizeInput($formData['dosen_pembimbing_1']),
                'dosen_pembimbing_2' => $this->sanitizeInput($formData['dosen_pembimbing_2']),
                'jumlah_peserta' => (int)$this->sanitizeInput($formData['jumlah_peserta']),
                'id_kategori' => (int)$this->sanitizeInput($formData['id_kategori']),
                'tanggal_mulai' => date("Y-m-d", strtotime($formData['tanggal_mulai'])),
                'tanggal_selesai' => date("Y-m-d", strtotime($formData['tanggal_selesai'])),
            ];

            // Upload file dan tambahkan ke data
            foreach ($this->fileRules as $key => $config) {
                $data[$key] = (isset($fileData[$key]) && $fileData[$key]['size'] > 0)
                    ? $this->uploadFile($fileData[$key], $config['allowed'], $config['size'], $key)
                    : null;
            }

            $query = "EXEC sp_InsertPrestasiPending @id_mahasiswa=?, @nama_kompetisi=?, @id_juara=?, @penyelenggara=?, 
            @event=?, @dosen_pembimbing_1=?, @dosen_pembimbing_2=?, @jumlah_peserta=?, 
            @id_kategori=?, @tanggal_mulai=?, @tanggal_selesai=?, 
            @foto_kompetisi=?, @sertifikat=?, @flyer=?, @surat_tugas=?, @karya_kompetisi=?";
            
            // Paramter untuk stored procedure
            $params = [
                $data['id_mahasiswa'], $data['nama_kompetisi'], $data['id_juara'], $data['penyelenggara'], 
                $data['event'], $data['dosen_pembimbing_1'], $data['dosen_pembimbing_2'], $data['jumlah_peserta'],
                $data['id_kategori'], $data['tanggal_mulai'], $data['tanggal_selesai'], 
                $data['foto_kompetisi'], $data['sertifikat'], $data['flyer'], $data['surat_tugas'], $data['karya_kompetisi']
            ];

            // Jalankan stored procedure dengan parameter yang diberikan
            $this->db->executeProcedure($query, $params);

            return "Data berhasil disimpan!";
        } catch (Exception $e) {
            throw new Exception("Kesalahan: " . $e->getMessage());
        }
    }

    public function closeConnection() {
        $this->db->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $db = new Database();
        $inputProses = new InputProses($db);

        $formData = $_POST;
        $fileData = $_FILES;

        // Proses form dan file
        $result = $inputProses->processForm($formData, $fileData);

        echo json_encode(['status' => 'success', 'message' => 'Data berhasil diterima']);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>
