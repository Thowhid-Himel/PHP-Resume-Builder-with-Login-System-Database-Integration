<?php
// PHP logic for resume builder with database integration
session_start();
$host = 'localhost';
$db = 'resume_builder';
$user = 'root';
$pass = '';
$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    if ($e->getCode() == 1049) {
        // Database doesn't exist, create it
        $dsnNoDb = "mysql:host=$host;charset=utf8mb4";
        try {
            $pdoTemp = new PDO($dsnNoDb, $user, $pass);
            $pdoTemp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdoTemp->exec("CREATE DATABASE `$db`");
            $pdoTemp = null;
            // Now connect to the new db
            $pdo = new PDO($dsn, $user, $pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e2) {
            die('Failed to create database: ' . $e2->getMessage());
        }
    } else {
        die('Connection failed: ' . $e->getMessage());
    }
}

// Create tables if not exist
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS resumes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    data LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)");

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['login'])) {
        $email = $_POST['email'];
        $password = $_POST['password'];
        $stmt = $pdo->prepare("SELECT id, password FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $userData = $stmt->fetch();
        if ($userData && password_verify($password, $userData['password'])) {
            $_SESSION['loggedin'] = true;
            $_SESSION['user_id'] = $userData['id'];
            $_SESSION['email'] = $email;
            header('Location: ' . $_SERVER['PHP_SELF'] . '?action=dashboard');
            exit;
        } else {
            $error = 'Invalid credentials';
        }
    } elseif (isset($_POST['register'])) {
        $email = $_POST['email'];
        $password = $_POST['password'];
        $stmt = $pdo->prepare("INSERT INTO users (email, password) VALUES (?, ?)");
        try {
            $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
            $_SESSION['loggedin'] = true;
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['email'] = $email;
            header('Location: ' . $_SERVER['PHP_SELF'] . '?action=dashboard');
            exit;
        } catch (PDOException $e) {
            $error = 'Email already exists';
        }
    } else {
        // Handle JSON for save_resume
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['save_resume'])) {
            if (!isset($_SESSION['loggedin'], $_SESSION['user_id'])) {
                http_response_code(401);
                echo 'Unauthorized';
                exit;
            }

            $resumeData = $input['resume_data'] ?? [];

            // If profile_pic is a data URL, save it as a file and store path instead.
            if (isset($resumeData['profile_pic']) && is_string($resumeData['profile_pic'])) {
                $pic = $resumeData['profile_pic'];
                if (preg_match('#^data:image/(png|jpe?g|webp);base64,#i', $pic, $m)) {
                    $ext = strtolower($m[1]);
                    if ($ext === 'jpeg') $ext = 'jpg';

                    $base64 = preg_replace('#^data:image/(png|jpe?g|webp);base64,#i', '', $pic);
                    $bin = base64_decode($base64, true);

                    if ($bin !== false) {
                        $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0755, true);
                        }

                        $fileName = 'profile_' . (int)$_SESSION['user_id'] . '_' . time() . '.' . $ext;
                        $filePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
                        if (@file_put_contents($filePath, $bin) !== false) {
                            // Save relative path so it works across environments
                            $resumeData['profile_pic'] = 'uploads/' . $fileName;
                        }
                    }
                }
            }

            $data = json_encode($resumeData);
            if (isset($input['resume_data']['resume_id'])) {
                try {
                    $stmt = $pdo->prepare("UPDATE resumes SET data = ?, created_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?");
                    $stmt->execute([$data, $input['resume_data']['resume_id'], $_SESSION['user_id']]);
                    echo 'Saved';
                } catch (PDOException $e) {
                    echo 'Error saving: ' . $e->getMessage();
                }
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO resumes (user_id, data) VALUES (?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $data]);
                    echo 'Saved';
                } catch (PDOException $e) {
                    echo 'Error saving: ' . $e->getMessage();
                }
            }
            exit;
        }
    }
}

if (!isset($_SESSION['loggedin'])) {
    // Show login/register form
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Login - Resume Builder</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gray-100 flex items-center justify-center min-h-screen">
        <div class="bg-white p-8 rounded shadow-md w-full max-w-md">
            <h2 class="text-2xl mb-4" id="form-title">Login</h2>
            <?php if (isset($error)) echo "<p class='text-red-500 mb-4'>$error</p>"; ?>
            <form method="post" id="auth-form">
                <input type="email" name="email" placeholder="Email" required class="w-full border p-2 mb-4 rounded focus:ring-2 focus:ring-blue-500 outline-none">
                <input type="password" name="password" placeholder="Password" required class="w-full border p-2 mb-4 rounded focus:ring-2 focus:ring-blue-500 outline-none">
                <button type="submit" name="login" class="w-full bg-blue-500 text-white p-2 rounded hover:bg-blue-700">Login</button>
            </form>
            <p class="mt-4 text-center">
                Don't have an account? <a href="#" onclick="toggleForm()" class="text-blue-500">Create one</a>
            </p>
        </div>
        <script>
            function toggleForm() {
                const title = document.getElementById('form-title');
                const form = document.getElementById('auth-form');
                const link = document.querySelector('p a');
                if (title.textContent === 'Login') {
                    title.textContent = 'Register';
                    form.innerHTML = `
                        <input type="email" name="email" placeholder="Email" required class="w-full border p-2 mb-4 rounded focus:ring-2 focus:ring-blue-500 outline-none">
                        <input type="password" name="password" placeholder="Password" required class="w-full border p-2 mb-4 rounded focus:ring-2 focus:ring-blue-500 outline-none">
                        <button type="submit" name="register" class="w-full bg-green-500 text-white p-2 rounded hover:bg-green-700">Register</button>
                    `;
                    link.parentElement.innerHTML = 'Already have an account? <a href="#" onclick="toggleForm()" class="text-blue-500">Login</a>';
                } else {
                    title.textContent = 'Login';
                    form.innerHTML = `
                        <input type="email" name="email" placeholder="Email" required class="w-full border p-2 mb-4 rounded focus:ring-2 focus:ring-blue-500 outline-none">
                        <input type="password" name="password" placeholder="Password" required class="w-full border p-2 mb-4 rounded focus:ring-2 focus:ring-blue-500 outline-none">
                        <button type="submit" name="login" class="w-full bg-blue-500 text-white p-2 rounded hover:bg-blue-700">Login</button>
                    `;
                    link.parentElement.innerHTML = 'Don\'t have an account? <a href="#" onclick="toggleForm()" class="text-blue-500">Create one</a>';
                }
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action == 'dashboard') {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Dashboard - Resume Builder</title>
            <script src="https://cdn.tailwindcss.com"></script>
        </head>
        <body class="bg-gray-100 flex items-center justify-center min-h-screen">
            <div class="bg-white p-8 rounded shadow-md w-full max-w-md text-center">
                <h2 class="text-2xl mb-6">Welcome, <?php echo htmlspecialchars($_SESSION['email']); ?>!</h2>
                <a href="?action=gallery" class="block w-full bg-blue-500 text-white p-3 rounded mb-4 hover:bg-blue-700">Resume Gallery</a>
                <a href="?action=builder" class="block w-full bg-green-500 text-white p-3 rounded mb-4 hover:bg-green-700">Create New Resume</a>
                <a href="?logout=1" class="block w-full bg-red-500 text-white p-3 rounded hover:bg-red-700">Logout</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    } elseif ($action == 'gallery') {
        $stmt = $pdo->prepare("SELECT id, data, created_at FROM resumes WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$_SESSION['user_id']]);
        $resumes = $stmt->fetchAll();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Resume Gallery - Resume Builder</title>
            <script src="https://cdn.tailwindcss.com"></script>
        </head>
        <body class="bg-gray-100 p-8">
            <div class="max-w-4xl mx-auto">
                <h2 class="text-2xl mb-6">Your Resumes</h2>
                <a href="?action=dashboard" class="bg-blue-500 text-white px-4 py-2 rounded mb-4 inline-block hover:bg-blue-700">Back to Dashboard</a>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($resumes as $resume): ?>
                        <div class="bg-white p-4 rounded shadow">
                            <h3 class="text-lg font-bold">Resume <?php echo $resume['id']; ?></h3>
                            <p class="text-sm text-gray-600">Created: <?php echo $resume['created_at']; ?></p>
                            <a href="?action=view&id=<?php echo $resume['id']; ?>" class="bg-blue-500 text-white px-3 py-1 rounded mt-2 inline-block hover:bg-blue-700">View</a>
                            <a href="?action=builder&id=<?php echo $resume['id']; ?>" class="bg-green-500 text-white px-3 py-1 rounded mt-2 inline-block hover:bg-green-700 ml-2">Edit</a>
                            <a href="?action=delete&id=<?php echo $resume['id']; ?>" onclick="return confirm('Delete this resume?')" class="bg-red-500 text-white px-3 py-1 rounded mt-2 inline-block hover:bg-red-700 ml-2">Delete</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    } elseif ($action == 'delete' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("DELETE FROM resumes WHERE id = ? AND user_id = ?");
        $stmt->execute([$_GET['id'], $_SESSION['user_id']]);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?action=gallery');
        exit;
    } elseif ($action == 'builder') {
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT data FROM resumes WHERE id = ? AND user_id = ?");
            $stmt->execute([$_GET['id'], $_SESSION['user_id']]);
            $resume = $stmt->fetch();
            if ($resume) {
                $_SESSION['resume_data'] = json_decode($resume['data'], true);
                $_SESSION['resume_id'] = $_GET['id'];
            }
        }
    } elseif ($action == 'view') {
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT data FROM resumes WHERE id = ? AND user_id = ?");
            $stmt->execute([$_GET['id'], $_SESSION['user_id']]);
            $resume = $stmt->fetch();
            if ($resume) {
                $_SESSION['resume_data'] = json_decode($resume['data'], true);
            }
        }
    }
} else {
    header('Location: ' . $_SERVER['PHP_SELF'] . '?action=dashboard');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Resume Builder</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        
        @media print {
            @page {
                size: A4;
                margin: 8mm;
            }
            html, body {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                height: auto !important;
                background: #fff !important;
                overflow: visible !important;
            }
            body * {
                visibility: hidden;
            }
            #print-content, #print-content * {
                visibility: visible;
            }
            #print-content {
                position: absolute;
                left: 0;
                top: 0;
                width: 100% !important;
                min-height: auto !important;
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .no-print {
                display: none !important;
            }
            section, header, .mb-4 {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }
        .preview-container {
            min-height: 297mm; /* A4 Ratio */
            width: 210mm;
        }
        /* Template 1: Classic */
        .template-1 header {
            border-bottom-color: black;
        }
        .template-1 h3 {
            color: #333;
        }
        /* Template 2: Modern */
        .template-2 {
            background: #f8f9fa;
        }
        .template-2 header {
            border-bottom-color: #007bff;
            background: #e9ecef;
        }
        .template-2 h1 {
            color: #007bff;
        }
        .template-2 h3 {
            color: #007bff;
            border-bottom-color: #007bff;
        }
        .template-2 section {
            margin-bottom: 1.5rem;
        }
        /* Template 3: Minimal */
        .template-3 {
            background: white;
            font-family: 'Arial', sans-serif;
        }
        .template-3 header {
            border-bottom: 1px solid #ccc;
        }
        .template-3 h1 {
            color: #000;
            font-weight: normal;
        }
        .template-3 h3 {
            color: #666;
            text-transform: none;
            font-weight: normal;
            border-bottom: none;
            margin-bottom: 0.5rem;
        }
        .template-3 p, .template-3 li {
            color: #333;
        }
        /* Template 4: Professional */
        .template-4 header {
            border-bottom-color: #28a745;
        }
        .template-4 h1 {
            color: #28a745;
        }
        .template-4 h3 {
            color: #28a745;
            border-bottom-color: #28a745;
        }
        .template-4 section {
            border-left: 4px solid #28a745;
            padding-left: 1rem;
        }
        /* Template 5: Creative */
        .template-5 {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .template-5 header {
            border-bottom-color: #fff;
            background: rgba(255,255,255,0.1);
        }
        .template-5 h1 {
            color: #fff;
        }
        .template-5 h3 {
            color: #fff;
            border-bottom-color: #fff;
        }
        .template-5 p, .template-5 li, .template-5 span {
            color: #fff;
        }
        /* Template 6: Elegant */
        .template-6 {
            background: #f5f5f5;
            font-family: 'Georgia', serif;
        }
        .template-6 header {
            border-bottom-color: #d4af37;
            background: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .template-6 h1 {
            color: #d4af37;
            font-style: italic;
        }
        .template-6 h3 {
            color: #d4af37;
            text-transform: none;
            font-style: italic;
        }
        .template-6 section {
            background: #fff;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body class="bg-gray-100 font-sans">

    <div class="flex flex-col md:flex-row h-screen">
        
        <?php if (!isset($_GET['action']) || $_GET['action'] != 'view'): ?>
        <div class="w-full md:w-1/3 bg-white p-6 shadow-xl overflow-y-auto no-print">
            <h2 class="text-2xl font-bold mb-6 border-b pb-2">Resume Editor</h2>
            
            <div class="space-y-2 mb-4">
                <label class="flex items-center">
                    <input type="checkbox" id="toggle_summary" checked class="mr-2">
                    Show Summary
                </label>
                <label class="flex items-center">
                    <input type="checkbox" id="toggle_skills" checked class="mr-2">
                    Show Skills
                </label>
                <label class="flex items-center">
                    <input type="checkbox" id="toggle_experience" checked class="mr-2">
                    Show Experience
                </label>
                <label class="flex items-center">
                    <input type="checkbox" id="toggle_education" checked class="mr-2">
                    Show Education
                </label>
            </div>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Profile Picture</label>
                    <input type="file" id="profile_upload" accept="image/*" class="w-full border p-2 rounded mt-1">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Choose Template</label>
                    <select id="template_select" class="w-full border p-2 rounded mt-1 focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="1">Template 1 (Classic)</option>
                        <option value="2">Template 2 (Modern)</option>
                        <option value="3">Template 3 (Minimal)</option>
                        <option value="4">Template 4 (Professional)</option>
                        <option value="5">Template 5 (Creative)</option>
                        <option value="6">Template 6 (Elegant)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Full Name</label>
                    <input type="text" id="in_name" placeholder="Thowhid Hassan Himel" 
                           class="w-full border p-2 rounded mt-1 focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Phone Number</label>
                    <input type="text" id="in_phone" placeholder="222-15-6274" 
                           class="w-full border p-2 rounded mt-1 focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Email</label>
                    <input type="email" id="in_email" placeholder="your.email@example.com" 
                           class="w-full border p-2 rounded mt-1 focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Address</label>
                    <input type="text" id="in_address" placeholder="City, Country" 
                           class="w-full border p-2 rounded mt-1 focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Professional Summary</label>
                    <textarea id="in_summary" rows="4" placeholder="Brief description of your background..." 
                              class="w-full border p-2 rounded mt-1 focus:ring-2 focus:ring-blue-500 outline-none"></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Skills (One per line)</label>
                    <textarea id="in_skills" rows="3" placeholder="Skill 1
Skill 2" 
                              class="w-full border p-2 rounded mt-1 focus:ring-2 focus:ring-blue-500 outline-none"></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Experience</label>
                    <div id="experience_entries">
                        <!-- Experience entries will be added here -->
                    </div>
                    <button id="add_experience" class="w-full bg-green-600 text-white py-2 rounded-lg font-bold hover:bg-green-700 transition mt-4">
                        Add Experience
                    </button>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Education</label>
                    <div id="education_entries">
                        <!-- Education entries will be added here -->
                    </div>
                    <button id="add_education" class="w-full bg-green-600 text-white py-2 rounded-lg font-bold hover:bg-green-700 transition mt-4">
                        Add Education
                    </button>
                </div>

                <button onclick="window.print()" class="w-full bg-blue-600 text-white py-3 rounded-lg font-bold hover:bg-blue-700 transition">
                    Download PDF / Print
                </button>

                <button onclick="saveResume()" class="w-full bg-green-600 text-white py-2 rounded-lg font-bold hover:bg-green-700 transition mt-4">
                    Save Resume
                </button>

                <a href="?action=dashboard" class="w-full bg-gray-500 text-white py-2 rounded-lg font-bold hover:bg-gray-700 transition mt-4 text-center block">Back to Dashboard</a>

                <a href="?logout=1" class="w-full bg-red-600 text-white py-2 rounded-lg font-bold hover:bg-red-700 transition mt-4 text-center block">Logout</a>
            </div>
        </div>
        <?php endif; ?>

        <div class="<?php echo (isset($_GET['action']) && $_GET['action'] == 'view') ? 'w-full' : 'w-full md:w-2/3'; ?> flex justify-center p-8 overflow-y-auto">
            <?php if (isset($_GET['action']) && $_GET['action'] == 'view'): ?>
            <a href="?action=gallery" class="bg-blue-500 text-white px-4 py-2 rounded mb-4 inline-block hover:bg-blue-700">Back to Gallery</a>
            <?php endif; ?>
            <div id="print-content" class="preview-container bg-white p-12 shadow-2xl print-area template-1">
                
                <header class="border-b-4 border-black pb-4 mb-6 flex items-center">
                    <img id="profile_pic" src="" alt="Profile Picture" class="w-20 h-20 rounded-full mr-6" style="display: none;">
                    <div class="text-center flex-1">
                        <h1 id="out_name" class="text-4xl font-black uppercase tracking-tight">FAISAL ABEDIN RAHAT</h1>
                        <p id="out_contact" class="text-gray-600 mt-1 text-lg">222-15-6112 | your.email@example.com | City, Country</p>
                    </div>
                </header>

                <section id="section_summary" class="mb-8">
                    <h3 class="text-lg font-bold uppercase text-gray-800 border-b mb-2">Summary</h3>
                    <p id="out_summary" class="text-gray-700 leading-relaxed italic text-sm">
                        Enter your professional background in the editor to see it update here.
                    </p>
                </section>

                <section id="section_skills" class="mb-8">
                    <h3 class="text-lg font-bold uppercase text-gray-800 border-b mb-2">Skills</h3>
                    <ul id="out_skills" class="list-disc list-inside text-sm text-gray-700 grid grid-cols-2 gap-1">
                        </ul>
                </section>

                <section id="section_experience" class="mb-8">
                    <h3 class="text-lg font-bold uppercase text-gray-800 border-b mb-2">Experience</h3>
                    <div id="out_experience">
                        <!-- Experience entries will be added here -->
                    </div>
                </section>

                <section id="section_education" class="mb-8">
                    <h3 class="text-lg font-bold uppercase text-gray-800 border-b mb-2">Education</h3>
                    <div id="out_education">
                        <!-- Education entries will be added here -->
                    </div>
                </section>
            </div>
        </div>
    </div>

    <script>
        const isEditorMode = !!document.getElementById('in_name');
        const toggles = ['summary', 'skills', 'experience', 'education'];

        // Mapping inputs to their preview counterparts
        const fields = [
            { input: 'in_name', output: 'out_name', default: 'Thowhid Hassan Himel' },
            { input: 'in_summary', output: 'out_summary', default: 'Brief description of your background...' }
        ];

        // Contact info
        function updateContact() {
            if (!isEditorMode) return;
            const phone = document.getElementById('in_phone').value || '222-15-6274';
            const email = document.getElementById('in_email').value || 'your.email@example.com';
            const address = document.getElementById('in_address').value || 'City, Country';
            document.getElementById('out_contact').innerText = `${phone} | ${email} | ${address}`;
        }

        // Special handling for skills (converting lines to list items)
        function updateSkillsPreview(skillsText) {
            const skillOutput = document.getElementById('out_skills');
            const lines = (skillsText || '').split('\n').filter(line => line.trim() !== "");
            skillOutput.innerHTML = lines.map(skill => `<li>${skill}</li>`).join('');
        }

        // Template selection
        function applyTemplate(template) {
            const preview = document.getElementById('print-content');
            preview.classList.remove('template-1', 'template-2', 'template-3', 'template-4', 'template-5', 'template-6');
            preview.classList.add(`template-${template || '1'}`);
        }

        // Section toggle application
        function applyToggleState(toggleData = {}) {
            toggles.forEach(section => {
                const enabled = toggleData[section] !== false;
                const sectionEl = document.getElementById(`section_${section}`);
                sectionEl.style.display = enabled ? 'block' : 'none';
                const toggleEl = document.getElementById(`toggle_${section}`);
                if (toggleEl) toggleEl.checked = enabled;
            });
        }

        if (isEditorMode) {
            fields.forEach(field => {
                const inputEl = document.getElementById(field.input);
                const outputEl = document.getElementById(field.output);
                inputEl.addEventListener('input', () => {
                    outputEl.innerText = inputEl.value || field.default;
                });
            });

            ['in_phone', 'in_email', 'in_address'].forEach(id => {
                document.getElementById(id).addEventListener('input', updateContact);
            });
            updateContact();

            const skillInput = document.getElementById('in_skills');
            skillInput.addEventListener('input', () => {
                updateSkillsPreview(skillInput.value);
            });

            toggles.forEach(section => {
                document.getElementById(`toggle_${section}`).addEventListener('change', (e) => {
                    const sectionEl = document.getElementById(`section_${section}`);
                    sectionEl.style.display = e.target.checked ? 'block' : 'none';
                });
            });

            document.getElementById('template_select').addEventListener('change', (e) => {
                applyTemplate(e.target.value);
            });

            const fileInput = document.getElementById('profile_upload');
            fileInput.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = (event) => {
                        document.getElementById('profile_pic').src = event.target.result;
                        document.getElementById('profile_pic').style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                }
            });
        }

        // Education management
        let educations = [];

        function addEducation(degree = '', institution = '', year = '') {
            const index = educations.length;
            educations.push({ degree, institution, year });
            const entryDiv = document.createElement('div');
            entryDiv.className = 'mb-4 border p-4 rounded';
            entryDiv.innerHTML = `
                <div class="mb-2">
                    <input type="text" placeholder="Degree" value="${degree}" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 outline-none" oninput="updateEducation(${index}, 'degree', this.value)">
                </div>
                <div class="mb-2">
                    <input type="text" placeholder="Institution" value="${institution}" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 outline-none" oninput="updateEducation(${index}, 'institution', this.value)">
                </div>
                <div class="mb-2 flex">
                    <input type="text" placeholder="Year" value="${year}" class="flex-1 border p-2 rounded focus:ring-2 focus:ring-blue-500 outline-none" oninput="updateEducation(${index}, 'year', this.value)">
                    <button onclick="removeEducation(${index})" class="ml-2 bg-red-600 text-white px-3 py-2 rounded hover:bg-red-700">Remove</button>
                </div>
            `;
            document.getElementById('education_entries').appendChild(entryDiv);
            updateEducationPreview();
        }

        function updateEducation(index, field, value) {
            educations[index][field] = value;
            updateEducationPreview();
        }

        function removeEducation(index) {
            educations.splice(index, 1);
            // Re-render all entries
            document.getElementById('education_entries').innerHTML = '';
            educations.forEach((edu, i) => addEducation(edu.degree, edu.institution, edu.year));
            updateEducationPreview();
        }

        function updateEducationPreview() {
            const outEl = document.getElementById('out_education');
            outEl.innerHTML = educations.map(edu => `
                <div class="mb-4">
                    <div class="flex justify-between">
                        <span class="font-bold">${edu.degree || 'Degree'}</span>
                        <span class="text-gray-600">${edu.year || 'Year'}</span>
                    </div>
                    <p class="text-sm italic">${edu.institution || 'Institution'}</p>
                </div>
            `).join('');
        }

        if (isEditorMode) {
            document.getElementById('add_education').addEventListener('click', () => addEducation());
            // Add one default education
            addEducation();
        }

        // Experience management
        let experiences = [];

        function addExperience(company = '', title = '', dates = '', desc = '') {
            const index = experiences.length;
            experiences.push({ company, title, dates, desc });
            const entryDiv = document.createElement('div');
            entryDiv.className = 'mb-4 border p-4 rounded';
            entryDiv.innerHTML = `
                <div class="mb-2">
                    <input type="text" placeholder="Company Name" value="${company}" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 outline-none" oninput="updateExperience(${index}, 'company', this.value)">
                </div>
                <div class="mb-2">
                    <input type="text" placeholder="Job Title" value="${title}" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 outline-none" oninput="updateExperience(${index}, 'title', this.value)">
                </div>
                <div class="mb-2">
                    <input type="text" placeholder="Dates" value="${dates}" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 outline-none" oninput="updateExperience(${index}, 'dates', this.value)">
                </div>
                <div class="mb-2">
                    <textarea placeholder="Description" rows="3" class="w-full border p-2 rounded focus:ring-2 focus:ring-blue-500 outline-none" oninput="updateExperience(${index}, 'desc', this.value)">${desc}</textarea>
                </div>
                <button onclick="removeExperience(${index})" class="bg-red-600 text-white px-3 py-2 rounded hover:bg-red-700">Remove</button>
            `;
            document.getElementById('experience_entries').appendChild(entryDiv);
            updateExperiencePreview();
        }

        function updateExperience(index, field, value) {
            experiences[index][field] = value;
            updateExperiencePreview();
        }

        function removeExperience(index) {
            experiences.splice(index, 1);
            // Re-render all entries
            document.getElementById('experience_entries').innerHTML = '';
            experiences.forEach((exp, i) => addExperience(exp.company, exp.title, exp.dates, exp.desc));
            updateExperiencePreview();
        }

        function updateExperiencePreview() {
            const outEl = document.getElementById('out_experience');
            outEl.innerHTML = experiences.map(exp => `
                <div class="mb-4">
                    <div class="flex justify-between">
                        <span class="font-bold">${exp.company || 'Company Name'}</span>
                        <span class="text-gray-600">${exp.dates || 'Dates'}</span>
                    </div>
                    <p class="text-sm italic">${exp.title || 'Job Title'}</p>
                    <p class="text-sm text-gray-700">${exp.desc || 'Description'}</p>
                </div>
            `).join('');
        }

        if (isEditorMode) {
            document.getElementById('add_experience').addEventListener('click', () => addExperience());
            // Add one default experience
            addExperience();
        }

        // Save resume
        function saveResume() {
            const data = {
                name: document.getElementById('in_name').value,
                phone: document.getElementById('in_phone').value,
                email: document.getElementById('in_email').value,
                address: document.getElementById('in_address').value,
                summary: document.getElementById('in_summary').value,
                skills: document.getElementById('in_skills').value,
                experiences: experiences,
                educations: educations,
                profile_pic: document.getElementById('profile_pic').src,
                template: document.getElementById('template_select').value,
                toggles: {
                    summary: document.getElementById('toggle_summary').checked,
                    skills: document.getElementById('toggle_skills').checked,
                    experience: document.getElementById('toggle_experience').checked,
                    education: document.getElementById('toggle_education').checked
                }
            };
            if (window.resumeId) {
                data.resume_id = window.resumeId;
            }
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ save_resume: true, resume_data: data })
            })
            .then(async (response) => {
                const text = await response.text();
                if (!response.ok) throw new Error(text || 'Save failed');
                if (!/^Saved/i.test(text.trim())) throw new Error(text || 'Save failed');
                alert('Resume saved!');
            })
            .catch((err) => {
                alert('Save failed: ' + (err?.message || err));
            });
        }

        // Load resume data if available
        <?php if (isset($_SESSION['resume_data'])): ?>
        const resumeData = <?php echo json_encode($_SESSION['resume_data'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        // Populate preview for both builder and view modes
        document.getElementById('out_name').innerText = resumeData.name || 'FAISAL ABEDIN RAHAT';
        const phone = resumeData.phone || '222-15-6112';
        const email = resumeData.email || 'your.email@example.com';
        const address = resumeData.address || 'City, Country';
        document.getElementById('out_contact').innerText = `${phone} | ${email} | ${address}`;
        document.getElementById('out_summary').innerText = resumeData.summary || 'Brief description of your background...';
        updateSkillsPreview(resumeData.skills || '');

        if (resumeData.profile_pic && resumeData.profile_pic !== 'data:,') {
            document.getElementById('profile_pic').src = resumeData.profile_pic;
            document.getElementById('profile_pic').style.display = 'block';
        }

        applyTemplate(resumeData.template || '1');
        applyToggleState(resumeData.toggles || {});

        // Populate experiences preview
        document.getElementById('out_experience').innerHTML = (resumeData.experiences || []).map(exp => `
            <div class="mb-4">
                <div class="flex justify-between">
                    <span class="font-bold">${exp.company || 'Company Name'}</span>
                    <span class="text-gray-600">${exp.dates || 'Dates'}</span>
                </div>
                <p class="text-sm italic">${exp.title || 'Job Title'}</p>
                <p class="text-sm text-gray-700">${exp.desc || 'Description'}</p>
            </div>
        `).join('');

        // Populate educations preview
        document.getElementById('out_education').innerHTML = (resumeData.educations || []).map(edu => `
            <div class="mb-4">
                <div class="flex justify-between">
                    <span class="font-bold">${edu.degree || 'Degree'}</span>
                    <span class="text-gray-600">${edu.year || 'Year'}</span>
                </div>
                <p class="text-sm italic">${edu.institution || 'Institution'}</p>
            </div>
        `).join('');

        // Populate editor fields only in builder mode
        if (isEditorMode) {
            document.getElementById('in_name').value = resumeData.name || '';
            document.getElementById('in_phone').value = resumeData.phone || '';
            document.getElementById('in_email').value = resumeData.email || '';
            document.getElementById('in_address').value = resumeData.address || '';
            document.getElementById('in_summary').value = resumeData.summary || '';
            document.getElementById('in_skills').value = resumeData.skills || '';
            updateContact();
            updateSkillsPreview(resumeData.skills || '');

            document.getElementById('template_select').value = resumeData.template || '1';
            experiences = [];
            document.getElementById('experience_entries').innerHTML = '';
            (resumeData.experiences || []).forEach(exp => addExperience(exp.company, exp.title, exp.dates, exp.desc));

            educations = [];
            document.getElementById('education_entries').innerHTML = '';
            (resumeData.educations || []).forEach(edu => addEducation(edu.degree, edu.institution, edu.year));
        }

        if (resumeData.resume_id) {
            window.resumeId = resumeData.resume_id;
        }
        <?php endif; ?>
    </script>
</body>
</html>