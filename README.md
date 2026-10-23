# Nutriendo Hábitos

Web de Andrea Argüello Arbe, dietista-nutricionista en Valladolid.

## Qué hay aquí (es lo que se sube a la raíz del hosting)
- Una página HTML por sección, preparada para Google: título, descripción, URL canónica, datos estructurados (negocio local, persona, servicio, preguntas frecuentes, migas de pan) e imagen para redes (`img/og.jpg`).
  - `index.html`, `dietista-nutricionista-en-valladolid.html`, `nutricion-deportiva-valladolid.html`, `nutricion-oncologica-valladolid.html`, `nutricionista-online.html`, `nuestros-servicios.html`, `reservar.html`, `contacto.html`, `blog-nutricion.html`, `tips.html`, `cursos-talleres.html`, `asesoria-externa.html`, `publicaciones.html`, `proteccion-de-datos.html`.
- `sitemap.xml` y `robots.txt`.
- `enviar.php`: formulario de contacto → nutricion.arguello@gmail.com, asunto «RESERVA WEB».
- `reservas/`: calendario de citas (`api.php`), panel privado de Andrea (`admin.php`) y `datos/` (se rellena en el servidor; no se sube a GitHub).
- Necesita PHP (hosting de DonDominio). Sin base de datos, sin servicios externos, sin claves en el código.

## `_fuente/` (no se sube al hosting)
- `vista-previa-una-pagina.html`: la web en un solo archivo, la que se edita.
- `build.py`: genera las páginas separadas. `python3 build.py definitiva` (raíz, con sitemap) o `python3 build.py prueba` (carpeta /nueva/, con noindex).
