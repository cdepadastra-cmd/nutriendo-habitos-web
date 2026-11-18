# Web de Centro Nuha (centronuha.es)

- Fuente: `build.py` genera la web estática en `web/` (`python3 build.py`). Fotos en `imagenes/` (se copian a `web/assets/images/`).
- Publicar: al hacer push a `main` con cambios en `centro-nuha/`, la automatización «Publicar Centro Nuha» genera la web y la sube por FTP si existen los secrets `NUHA_FTP_SERVIDOR`, `NUHA_FTP_USUARIO`, `NUHA_FTP_CONTRASENA` (y opcional `NUHA_FTP_CARPETA`). Si no, la deja para descargar en la ejecución (Artifacts) y se sube a mano con FileZilla.
- Profesionales: fisioterapia (Amaya Arnaiz), acupuntura (Adolfo Argüello Fernández, col. 474706370), nutrición (Andrea Argüello Arbe, Nutriendo Hábitos). No inventar datos de los profesionales.
- Formulario de contacto (contacto.html#formulario): envía al correo del centro mediante un Google Apps Script (código en `apps-script-formulario.gs`). La dirección del script desplegado (https://script.google.com/macros/s/.../exec) va en `formulario_url.txt`; sin ella el formulario pide llamar por teléfono.
