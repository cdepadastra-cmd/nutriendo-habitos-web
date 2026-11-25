# Ideas pendientes para Nutriendo Hábitos (guardadas el 25-11-2026)

Ideas propuestas que aún no se han hecho. Cuando Jairo pregunte por más ideas, empezar por aquí.

## Herramientas
- **Generador de menú semanal y lista de la compra**: a partir de las recetas de «¿Qué cocino con lo que tengo?» (recetas.json), elegir días/personas/preferencias (vegetariano, sin gluten…) y sacar menú de comidas y cenas + lista de la compra agrupada. Búsqueda muy frecuente («menú semanal saludable»).
- Calculadora de raciones / «método del plato» interactivo (idea secundaria).

## Mejoras de las herramientas ya publicadas
- Calendario de temporada: enlazar cada fruta/verdura con recetas de la herramienta de recetas.
- Tabla de nutrientes: páginas propias por nutriente (ya hechas como borradores «alimentos ricos en…», pendientes de revisión).

## Contenido que necesita datos de Andrea
- Precios de la consulta: el borrador se eliminó a petición de Jairo (25-11-2026); retomar solo si lo pide.
- Confirmar si trata menopausia (borrador «alimentacion-menopausia»).
- Revisión de la guía de quimioterapia (borrador «alimentacion-cancer-quimioterapia»).

## Borradores
Todos los artículos están en la carpeta oculta de la web (ver `_fuente/build.py`, CARPETA_BORRADORES) con noindex y sin enlaces.
Fuente editable: `_fuente/borradores/articulos.py` (genera `_fuente/borradores.html`).
Para publicar uno: mover su bloque a `nutriendo-habitos.html` y darlo de alta en `build.py` (PAGINAS, ALIAS, NAVMAP, GUIAS, llms.txt) y en el blog.
