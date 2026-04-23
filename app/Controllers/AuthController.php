<?php
namespace App\Controllers;

use App\Models\Database; // NOVO: Dodajemo Database model za rad sa prodavnicama
use App\Support\Csrf;

class AuthController {
    
    public function login() {
        // 1. Provera: Ako je admin već prijavljen I izabrao prodavnicu, idi na dashboard
        if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true && isset($_SESSION['store_hash'])) {
            header('Location: ?route=dashboard');
            exit;
        }
        
        // 2. Provera: Ako je admin prijavljen ALI NIJE izabrao prodavnicu, idi na izbor prodavnice
        if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
            header('Location: ?route=auth&action=selectStore');
            exit;
        }
        
        // Handle login form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validateRequest()) {
                http_response_code(403);
                $this->renderLoginView('Sesija je istekla. Pokušajte ponovo.');
                return;
            }

            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            
            if ($this->authenticate($username, $password)) {
                session_regenerate_id(true);
                $_SESSION['authenticated'] = true;
                $_SESSION['username'] = $username;
                $_SESSION['login_time'] = time();
                
                // NOVO: Preusmeri na izbor prodavnice nakon uspešne prijave
                header('Location: ?route=auth&action=selectStore');
                exit;
            } else {
                $error = 'Pogrešno korisničko ime ili lozinka';
            }
        }
        
        // Display login form
        $this->renderLoginView($error ?? null);
    }
    
    /**
     * NOVO: Prikazuje listu registrovanih prodavnica koje admin može da izabere.
     */
    public function selectStore() {
        // Proveri da li je admin prijavljen
        if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
            header('Location: ?route=auth&action=login');
            exit;
        }
        
        // Dohvati listu prodavnica iz baze
        $db = Database::getInstance();
        $stores = $this->getRegisteredStores($db); 
        
        // Ako postoji samo jedna prodavnica, automatski je izaberi
        if (count($stores) === 1) {
            header('Location: ?route=auth&action=setStore&store_hash=' . $stores[0]['store_hash']);
            exit;
        }

        // Prikaži stranicu za izbor
        $error = $_GET['error'] ?? null;
        if ($error === 'invalid_hash') {
            $error = 'Greška: Izabrana prodavnica ne postoji ili nije aktivna.';
        }
        
        $this->renderStoreSelectionView($stores, $error);
    }
    
    /**
     * NOVO: Postavlja izabrani store_hash i access_token u sesiju.
     */
    public function setStore() {
        // Proveri da li je admin prijavljen
        if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
            header('Location: ?route=auth&action=login');
            exit;
        }
        
        $storeHash = $_GET['store_hash'] ?? null;
        
        if (!$storeHash) {
            header('Location: ?route=auth&action=selectStore&error=missing_hash');
            exit;
        }
        
        $db = Database::getInstance();
        $storeData = $this->getStoreCredentials($db, $storeHash); 
        
        if (!$storeData) {
            header('Location: ?route=auth&action=selectStore&error=invalid_hash');
            exit;
        }
        
        // Postavi Store kontekst u sesiju
        $_SESSION['store_hash'] = $storeData['store_hash'];
        $_SESSION['access_token'] = $storeData['access_token'];
        
        // Ažuriraj vreme poslednjeg pristupa u bazi (opciono, ali dobro)
        $db->query("UPDATE bigcommerce_stores SET last_accessed = NOW() WHERE store_hash = ?", [$storeHash]);
        
        // Preusmeri na dashboard
        header('Location: ?route=dashboard');
        exit;
    }

    public function logout() {
        // Dodajemo i brisanje store konteksta iz sesije prilikom odjave
        unset($_SESSION['store_hash']);
        unset($_SESSION['access_token']);
        
        session_destroy();
        header('Location: ?route=auth&action=login');
        exit;
    }
    
    private function authenticate($username, $password) {
        return $username === \Config::$ADMIN_USERNAME && 
               password_verify($password, \Config::$ADMIN_PASSWORD);
    }
    
    // -- POMOĆNE METODE ZA BAZU --
    
    private function getRegisteredStores($db) {
        return $db->fetchAll(
            "SELECT store_hash, context, installed_at FROM bigcommerce_stores WHERE is_active = 1 ORDER BY installed_at DESC"
        );
    }

    private function getStoreCredentials($db, $storeHash) {
        return $db->fetchOne(
            "SELECT store_hash, access_token FROM bigcommerce_stores WHERE store_hash = ?", 
            [$storeHash]
        );
    }

    // -- RENDERING METODE (RENDERED OVDE RADI JEDNOSTAVNOSTI) --

    private function renderLoginView($error = null) {
        // ... (isti HTML kao što ste naveli za login)
        ?>
        <!DOCTYPE html>
        <html lang="sr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Login - Promora</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                
                .login-container {
                    background: white;
                    padding: 40px;
                    border-radius: 12px;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                    width: 100%;
                    max-width: 400px;
                }
                
                .login-header {
                    text-align: center;
                    margin-bottom: 30px;
                }
                
                .login-header h1 {
                    color: #1f2937;
                    font-size: 28px;
                    margin-bottom: 8px;
                }
                
                .login-header p {
                    color: #6b7280;
                    font-size: 14px;
                }
                
                .alert {
                    padding: 12px 16px;
                    border-radius: 6px;
                    margin-bottom: 20px;
                    background: #fee2e2;
                    color: #991b1b;
                    border: 1px solid #fca5a5;
                }
                
                .form-group {
                    margin-bottom: 20px;
                }
                
                .form-label {
                    display: block;
                    margin-bottom: 8px;
                    font-weight: 500;
                    color: #374151;
                }
                
                .form-input {
                    width: 100%;
                    padding: 12px;
                    border: 1px solid #d1d5db;
                    border-radius: 6px;
                    font-size: 14px;
                    transition: all 0.2s;
                }
                
                .form-input:focus {
                    outline: none;
                    border-color: #667eea;
                    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
                }
                
                .btn-login {
                    width: 100%;
                    padding: 12px;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    border: none;
                    border-radius: 6px;
                    font-size: 16px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: transform 0.2s;
                }
                
                .btn-login:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
                }
                
                .login-footer {
                    margin-top: 20px;
                    text-align: center;
                    color: #6b7280;
                    font-size: 12px;
                }
            </style>
        </head>
        <body>
            <div class="login-container">
                <div class="login-header">
                    <h1>🛍️ Promotion Manager</h1>
                    <p>Prijavite se za nastavak</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                
                <form method="POST" action="?route=auth&action=login">
                    <?= Csrf::inputField() ?>
                    <div class="form-group">
                        <label class="form-label" for="username">Korisničko ime</label>
                        <input type="text" 
                               id="username" 
                               name="username" 
                               class="form-input" 
                               required 
                               autofocus
                               autocomplete="username">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="password">Lozinka</label>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               class="form-input" 
                               required
                               autocomplete="current-password">
                    </div>
                    
                    <button type="submit" class="btn-login">
                        Prijavi se
                    </button>
                </form>
                
                <div class="login-footer">
                    Promotion Manager v1.0 - Powered by BigCommerce
                </div>
            </div>
        </body>
        </html>
        <?php
    }

    private function renderStoreSelectionView($stores, $error = null) {
        ?>
        <!DOCTYPE html>
        <html lang="sr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Izbor Prodavnice</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .container {
                    background: white;
                    padding: 40px;
                    border-radius: 12px;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                    width: 100%;
                    max-width: 600px;
                }
                .login-header {
                    text-align: center;
                    margin-bottom: 30px;
                }
                .login-header h1 {
                    color: #1f2937;
                    font-size: 28px;
                    margin-bottom: 8px;
                }
                .login-header p {
                    color: #6b7280;
                    font-size: 14px;
                }
                .alert {
                    padding: 12px 16px;
                    border-radius: 6px;
                    margin-bottom: 20px;
                    background: #fee2e2;
                    color: #991b1b;
                    border: 1px solid #fca5a5;
                }
                .store-list a {
                    display: block;
                    padding: 15px;
                    margin-bottom: 10px;
                    border: 1px solid #e5e7eb;
                    border-radius: 8px;
                    text-decoration: none;
                    color: #1f2937;
                    transition: background-color 0.2s, border-color 0.2s;
                    font-weight: 500;
                }
                .store-list a:hover {
                    background-color: #f3f4f6;
                    border-color: #667eea;
                }
                .store-name {
                    font-size: 16px;
                    color: #3b82f6;
                }
                .store-hash {
                    font-size: 12px;
                    color: #6b7280;
                }
                .btn-login { /* Koristimo isti stil za dugme Odjavi se */
                    padding: 12px;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    border: none;
                    border-radius: 6px;
                    font-size: 16px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: transform 0.2s;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="login-header">
                    <h1>Izaberite Prodavnicu</h1>
                    <p>Izaberite BigCommerce prodavnicu kojoj želite da pristupite.</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if (empty($stores)): ?>
                    <div class="alert" style="background: #fffbeb; color: #92400e;">
                        Nema registrovanih BigCommerce prodavnica. Instalirajte aplikaciju na BigCommerce store.
                    </div>
                <?php else: ?>
                    <div class="store-list">
                        <?php foreach ($stores as $store): 
                            $contextParts = explode('/', $store['context']);
                            $displayContext = end($contextParts); // Prikazuje samo hash
                        ?>
                            <a href="?route=auth&action=setStore&store_hash=<?= htmlspecialchars($store['store_hash']) ?>">
                                <div class="store-name">
                                    <span style="color: #667eea;">#<?= htmlspecialchars($displayContext) ?></span>
                                    (Instalirano: <?= date('d.m.Y.', strtotime($store['installed_at'])) ?>)
                                </div>
                                <div class="store-hash">Hash: <?= htmlspecialchars($store['store_hash']) ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <div style="margin-top: 30px; text-align: center;">
                    <a href="?route=auth&action=logout" class="btn-login" style="display: inline-block; width: auto; background: #9ca3af;">Odjavi se</a>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
}
