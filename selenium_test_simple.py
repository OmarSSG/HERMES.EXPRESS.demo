from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from webdriver_manager.chrome import ChromeDriverManager
import time

def test_savar_login():
    """
    Versión simplificada para probar el login en SAVAR Express
    """
    driver = None
    try:
        print("=== PRUEBA DE LOGIN SAVAR EXPRESS ===")
        
        # Configuración mínima del navegador
        chrome_options = Options()
        chrome_options.add_argument('--no-sandbox')
        chrome_options.add_argument('--disable-dev-shm-usage')
        
        # Crear driver
        service = Service(ChromeDriverManager().install())
        driver = webdriver.Chrome(service=service, options=chrome_options)
        driver.maximize_window()
        
        print("1. Navegando a SAVAR Express...")
        driver.get("https://app.savarexpress.com.pe/sso/Inicio/")
        
        print("2. Esperando que cargue la página...")
        time.sleep(5)
        
        print("3. Buscando campos de login...")
        
        # Buscar campos de forma simple
        username_field = None
        password_field = None
        
        try:
            username_field = driver.find_element(By.CSS_SELECTOR, "input[type='text']")
            print("   [OK] Campo de usuario encontrado")
        except:
            print("   [ERROR] No se encontro campo de usuario")
            return False
            
        try:
            password_field = driver.find_element(By.CSS_SELECTOR, "input[type='password']")
            print("   [OK] Campo de contrasena encontrado")
        except:
            print("   [ERROR] No se encontro campo de contrasena")
            return False
        
        # Credenciales reales
        usuario = "CHI.HER"
        contrasena = "10111222331"        
        print("4. Completando credenciales...")
        username_field.clear()
        username_field.send_keys(usuario)
        
        password_field.clear()
        password_field.send_keys(contrasena)
        
        print("5. Buscando boton de login...")
        try:
            submit_button = driver.find_element(By.CSS_SELECTOR, "button[type='submit']")
            print("   [OK] Boton encontrado, haciendo clic...")
            submit_button.click()
        except:
            print("   [INFO] No se encontro boton, enviando con Enter...")
            password_field.send_keys("\n")
        
        print("6. Esperando respuesta...")
        time.sleep(5)
        
        # Verificar resultado
        current_url = driver.current_url
        print(f"7. URL actual: {current_url}")
        
        if current_url != "https://app.savarexpress.com.pe/sso/Inicio/":
            print("   [EXITO] LOGIN EXITOSO - La URL cambio")
            print(f"   Nueva URL: {current_url}")
            
            # Mantener el navegador abierto para inspección
            print("\n8. Navegador abierto para inspeccion...")
            print("   Presiona Enter para continuar y cerrar el navegador...")
            input()
            
            return True
        else:
            print("   [INFO] La URL no cambio - verificar credenciales")
            
            # Buscar mensajes de error
            try:
                page_text = driver.find_element(By.TAG_NAME, "body").text
                if "error" in page_text.lower() or "incorrecto" in page_text.lower():
                    print(f"   [ERROR] Posible error encontrado en la pagina")
            except:
                pass
                
            print("\n   Navegador abierto para inspeccion...")
            print("   Presiona Enter para cerrar...")
            input()
            
            return False
            
    except Exception as e:
        print(f"ERROR: {str(e)}")
        return False
        
    finally:
        if driver:
            print("Cerrando navegador...")
            driver.quit()

if __name__ == "__main__":
    test_savar_login()
