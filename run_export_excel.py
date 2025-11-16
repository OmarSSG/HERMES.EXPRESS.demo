import os
import json
from pathlib import Path
from selenium_utils import (
    setup_driver,
    get_downloads_dir,
    navigate_menu_path,
    click_element_by_text,
    wait_modal_then_export_excel,
)


def main():
    # Configuración
    downloads = get_downloads_dir()
    user = os.environ.get("SAVAR_USER")
    password = os.environ.get("SAVAR_PASS")
    menu_path_raw = os.environ.get("MENU_PATH", "")
    open_modal_button = os.environ.get("OPEN_MODAL_BUTTON", "Detalle de Pedidos")
    modal_title_contains = os.environ.get("MODAL_TITLE", "DETALLE DE PEDIDOS")
    headless = os.environ.get("HEADLESS", "1") not in ("0", "false", "False")

    print(f"Descargas: {downloads}")
    print(f"Headless: {headless}")

    driver = setup_driver(headless=headless, download_dir=downloads)

    try:
        # Ir a la app principal (puede redirigir a login)
        driver.get("https://app.savarexpress.com.pe/govari/#")

        # Intentar login si hay credenciales
        if user and password:
            try:
                from selenium_utils import login_and_fetch_saver
                ok = login_and_fetch_saver(driver, user, password, timeout=45)
                print(f"Login ejecutado: {ok}")
            except Exception as e:
                print(f"No se pudo ejecutar login: {e}")
        else:
            print("Sin credenciales en variables de entorno, se intentará continuar con sesión existente.")

        # Navegar por menú si se proporcionó MENU_PATH (JSON con lista)
        if menu_path_raw.strip():
            try:
                items = json.loads(menu_path_raw)
                if isinstance(items, list) and items:
                    print(f"Navegando menú: {items}")
                    navigate_menu_path(driver, items)
            except Exception as e:
                print(f"MENU_PATH inválido: {e}")

        # Intentar abrir el modal con un botón por texto
        if open_modal_button:
            print(f"Intentando abrir modal con botón: {open_modal_button}")
            try:
                click_element_by_text(driver, open_modal_button, timeout=25)
            except Exception as e:
                print(f"No se pudo hacer clic en el botón de apertura del modal: {e}")

        # Exportar Excel esperando el modal
        ruta = wait_modal_then_export_excel(
            driver,
            timeout=50,
            modal_title_contains=modal_title_contains,
            button_text="Exportar Excel",
            download_dir=downloads,
            file_pattern="*.xls*",
        )

        if ruta:
            print(f"Excel descargado en: {ruta}")
            return 0
        else:
            print("No se pudo descargar el Excel (no se detectó el archivo a tiempo).")
            return 1
    finally:
        try:
            driver.quit()
        except Exception:
            pass


if __name__ == "__main__":
    raise SystemExit(main())
