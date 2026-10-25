#!/usr/bin/env python3
"""
Genera la web publicable por páginas (una URL por sección, buena para Google)
a partir de nutriendo-habitos.html (la versión de una sola página de la vista previa).

  python3 build.py prueba      -> build/prueba      (para /nueva/, con noindex)
  python3 build.py definitiva  -> build/definitiva  (para la raíz del dominio, con sitemap)
"""
import html, json, re, shutil, sys, datetime
from pathlib import Path

MODO = sys.argv[1] if len(sys.argv) > 1 else 'definitiva'
RAIZ = '/' if MODO == 'definitiva' else '/NUEVA/'   # carpeta del hosting (en el hosting importan las mayúsculas)
BASE = 'https://www.nutriendohabitos.es' + RAIZ
AQUI = Path(__file__).resolve().parent
SRC = (AQUI / 'nutriendo-habitos.html').read_text(encoding='utf-8')
SITE = AQUI / 'site'
OUT = AQUI / 'build' / MODO
HOY = datetime.date.today().isoformat()

# ---------------------------------------------------------------- páginas
# slug de data-page -> (archivo, título, descripción, prioridad sitemap)
PAGINAS = {
    'inicio': ('index.html',
        'Nutricionista en Valladolid · Consulta de nutrición | Nutriendo Hábitos',
        'Nutrición en Valladolid con Andrea Argüello, dietista-nutricionista desde 2013: nutrición deportiva, nutrición oncológica, pérdida de peso y digestiva. Presencial y online. Reserva tu cita.', '1.0'),
    'dietista-nutricionista-en-valladolid': ('dietista-nutricionista-en-valladolid.html',
        'Dietista-nutricionista en Valladolid · Consulta y servicios | Nutriendo Hábitos',
        'Consulta de nutrición en Valladolid con Andrea Argüello: pérdida y ganancia de peso, nutrición deportiva, patologías digestivas, oncológica y vegetariana. Bioimpedancia y seguimiento.', '0.9'),
    'nutricion-deportiva-valladolid': ('nutricion-deportiva-valladolid.html',
        'Nutrición deportiva en Valladolid · Nutricionista deportiva | Nutriendo Hábitos',
        'Nutricionista deportivo en Valladolid y online: escalada, running, trail, ciclismo y fuerza. Alimentación antes, durante y después del entrenamiento y composición corporal.', '0.9'),
    'nutricion-oncologica-valladolid': ('nutricion-oncologica-valladolid.html',
        'Nutrición oncológica en Valladolid y online | Nutriendo Hábitos',
        'Nutricionista oncológico en Valladolid y online, con Máster en Nutrición Oncológica: nutrición para pacientes oncológicos antes, durante y después del tratamiento.', '0.9'),
    'perdida-de-peso-valladolid': ('perdida-de-peso-valladolid.html',
        'Nutricionista para perder peso en Valladolid | Nutriendo Hábitos',
        'Nutricionista para adelgazar en Valladolid y online: pérdida de peso sin dietas milagro, ganancia de peso saludable y seguimiento con bioimpedancia. Andrea Argüello.', '0.9'),
    'patologias-digestivas-valladolid': ('patologias-digestivas-valladolid.html',
        'Nutricionista digestiva en Valladolid: SIBO, intolerancias y celiaquía | Nutriendo Hábitos',
        'Nutricionista en Valladolid y online para SIBO, intestino irritable, dieta FODMAP, intolerancias, déficit de DAO, celiaquía, Crohn y reflujo. Pauta adaptada a tu diagnóstico.', '0.9'),
    'alimentacion-vegetariana-vegana-valladolid': ('alimentacion-vegetariana-vegana-valladolid.html',
        'Nutricionista vegetariana y vegana en Valladolid | Nutriendo Hábitos',
        'Nutricionista en Valladolid y online para alimentación vegetariana y vegana bien planificada: proteína, B12, hierro y menús para cada etapa de la vida.', '0.8'),
    'nutricionista-online': ('nutricionista-online.html',
        'Nutricionista online · Consulta por videollamada | Nutriendo Hábitos',
        'Consulta de nutrición online con Andrea Argüello: dietas personalizadas y seguimiento por videollamada desde cualquier punto de España.', '0.8'),
    'nuestros-servicios': ('nuestros-servicios.html',
        'Servicios de nutrición y dietética en Valladolid | Nutriendo Hábitos',
        'Todos los servicios de la consulta: sobrepeso, ganancia de peso, TCA, educación nutricional, nutrición deportiva, alimentación infantil (BLW), patología digestiva y diabetes.', '0.7'),
    'reservar': ('reservar.html',
        'Reservar cita con nutricionista en Valladolid | Nutriendo Hábitos',
        'Reserva tu cita con la nutricionista Andrea Argüello: consulta presencial en Valladolid u online. Elige día y hora en el calendario.', '0.8'),
    'asesoria-externa': ('asesoria-externa.html',
        'Asesoría nutricional para colegios, comedores y empresas en Valladolid | Nutriendo Hábitos',
        'Asesoría nutricional externa en Valladolid para colegios, comedores escolares, residencias, empresas y madres de día.', '0.6'),
    'publicaciones': ('publicaciones.html',
        'Colaboraciones y Sputnik Investigación | Nutriendo Hábitos',
        'Colaboraciones de Nutriendo Hábitos: Sputnik Investigación, Universidad de Valladolid, Ultra de Gredos, Cruz Roja Palencia y otras entidades.', '0.5'),
    'cursos-talleres': ('cursos-talleres.html',
        'Cursos y talleres de nutrición en Valladolid y online | Nutriendo Hábitos',
        'Cursos y talleres de nutrición y cocina saludable en Valladolid y online, incluido el curso de nutrición en escalada.', '0.6'),
    'blog-nutricion': ('blog-nutricion.html',
        'Blog de nutrición: recetas y consejos | Nutriendo Hábitos',
        'Artículos, recetas e infografías de Andrea Argüello sobre alimentación, hambre emocional, bebidas isotónicas, leche sin lactosa y más.', '0.6'),
    'tips': ('tips.html',
        'Tips nutricionales | Nutriendo Hábitos',
        'Infografías y consejos rápidos de nutrición para el día a día.', '0.5'),
    'contacto': ('contacto.html',
        'Contacto · Nutricionista en Valladolid | Nutriendo Hábitos',
        'Contacta con Andrea Argüello, nutricionista en Valladolid: Calle Gregorio Fernández 4, teléfono 669 87 70 52, WhatsApp y formulario.', '0.7'),
    'proteccion-de-datos': ('proteccion-de-datos.html',
        'Protección de datos | Nutriendo Hábitos',
        'Información sobre el tratamiento de datos personales de Nutriendo Hábitos.', '0.1'),
}
ALIAS = {'servicios': 'dietista-nutricionista-en-valladolid', 'online': 'nutricionista-online', 'asesoria': 'asesoria-externa',
         'colaboraciones': 'publicaciones', 'cursos': 'cursos-talleres', 'blog': 'blog-nutricion',
         'todos-los-servicios': 'nuestros-servicios', 'nutricion-deportiva': 'nutricion-deportiva-valladolid',
         'privacidad': 'proteccion-de-datos', 'reserva': 'reservar', 'cita': 'reservar',
         'oncologica': 'nutricion-oncologica-valladolid', 'nutricion-oncologica': 'nutricion-oncologica-valladolid',
         'perdida-de-peso': 'perdida-de-peso-valladolid', 'patologias-digestivas': 'patologias-digestivas-valladolid',
         'vegana': 'alimentacion-vegetariana-vegana-valladolid'}
FILES = {k: v[0] for k, v in PAGINAS.items()}
FILES_JS = {k: ('./' if v == 'index.html' else v) for k, v in FILES.items()}
for a, b in ALIAS.items():
    FILES_JS[a] = FILES_JS[b]

# anclas que viven en una página concreta
ANCLAS = {}
for m in re.finditer(r'<div data-page="([^"]+)"', SRC):
    pass
NAV = [('inicio', 'Inicio'), ('dietista-nutricionista-en-valladolid', 'Servicios'), ('nutricionista-online', 'Online'),
       ('asesoria-externa', 'Asesoría externa'), ('publicaciones', 'Colaboraciones'), ('cursos-talleres', 'Cursos/Talleres'),
       ('blog-nutricion', 'Blog'), ('contacto', 'Contacto')]
NAVMAP = {'perdida-de-peso-valladolid': 'dietista-nutricionista-en-valladolid', 'patologias-digestivas-valladolid': 'dietista-nutricionista-en-valladolid',
          'alimentacion-vegetariana-vegana-valladolid': 'dietista-nutricionista-en-valladolid', 'nutricion-oncologica-valladolid': 'dietista-nutricionista-en-valladolid', 'nuestros-servicios': 'dietista-nutricionista-en-valladolid',
          'nutricion-deportiva-valladolid': 'dietista-nutricionista-en-valladolid', 'tips': 'blog-nutricion'}

WA = 'https://wa.me/34669877052?text=Hola%20Andrea%2C%20me%20gustar%C3%ADa%20pedir%20cita.%20%C2%BFQu%C3%A9%20huecos%20tienes%20disponibles%3F'
MAPS = 'https://maps.google.com/maps?q=Calle%20Gregorio%20Fernandez%204%2C%20Valladolid'
ARROW = '<svg class="i" viewBox="0 0 24 24"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg>'

# ---------------------------------------------------------------- trocear el original
style_end = SRC.index('</style>') + len('</style>')
head_extra = SRC[:style_end]                       # <title>, metas, fuentes y <style>
body = SRC[style_end:]
main_open = body.index('<main id="app">') + len('<main id="app">')
main_close = body.index('</main>')
antes_main, main_html, despues_main = body[:main_open], body[main_open:main_close], body[main_close:]

starts = [m.start() for m in re.finditer(r'<div data-page="', main_html)]
segmentos = {}
for i, st in enumerate(starts):
    fin = starts[i + 1] if i + 1 < len(starts) else len(main_html)
    seg = main_html[st:fin]
    seg = re.sub(r'\n<!-- =+ [^>]* =+ -->\s*$', '\n', seg.rstrip() + '\n')  # quita el comentario de la siguiente
    slug = re.match(r'<div data-page="([^"]+)"', seg).group(1)
    segmentos[slug] = seg.replace(f'<div data-page="{slug}" hidden>', f'<div data-page="{slug}">', 1)
assert set(segmentos) == set(PAGINAS), set(segmentos) ^ set(PAGINAS)

# ids que existen en cada página (para resolver #anclas que apuntan a otra página)
for slug, seg in segmentos.items():
    for idm in re.finditer(r'\sid="([^"]+)"', seg):
        ANCLAS.setdefault(idm.group(1), f'{FILES[slug]}#{idm.group(1)}')

def resolver_href(token: str, slug_actual: str, ids_actuales: set) -> str | None:
    t = ALIAS.get(token, token)
    if not t or t in ids_actuales:
        return None
    if t in FILES:
        return './' if FILES[t] == 'index.html' else FILES[t]
    if t in ANCLAS:
        return ANCLAS[t].replace('index.html#', './#')
    return None

def reescribir_enlaces(html_txt: str, slug: str) -> str:
    ids = set(re.findall(r'\sid="([^"]+)"', segmentos[slug]))
    def sub(m):
        nuevo = resolver_href(html.unescape(m.group(1)), slug, ids)
        return f'href="{nuevo}"' if nuevo else m.group(0)
    return re.sub(r'href="#([^"]*)"', sub, html_txt)

def hero_estatico(seg: str) -> str:
    def sub(m):
        attrs = dict(re.findall(r'data-([a-z0-9]+)="([^"]*)"', m.group(0)))
        if attrs.get('extra') == 'booking':
            ctas = (f'<a class="btn btn-wa" href="{WA}" target="_blank" rel="noopener"><svg class="i" viewBox="0 0 24 24" data-ic="wa"></svg> Escribir por WhatsApp</a>'
                    f'<a class="btn btn-outline-light" href="tel:+34669877052"><svg class="i" viewBox="0 0 24 24" data-ic="phone"></svg> Llamar</a>')
        else:
            ctas = f'<a class="btn btn-secondary" href="#reservar">Encuentra tu consulta {ARROW}</a>'
            if attrs.get('extra') == 'contact':
                ctas += (f'<a class="btn btn-outline-light" href="{MAPS}" target="_blank" rel="noopener">Ver ubicación</a>'
                         f'<a class="btn btn-wa" href="{WA}" target="_blank" rel="noopener"><svg class="i" viewBox="0 0 24 24" data-ic="wa"></svg> Escribir por WhatsApp</a>')
            else:
                ctas += '<a class="btn btn-outline-light" href="#contacto">Pide tu cita</a>'
        return (m.group(0) + f'<img src="img/{attrs["hero"]}.webp" alt="" fetchpriority="high"><div class="page-hero-shade"></div><div class="wrap">'
                f'<nav class="crumbs" aria-label="Ruta"><a href="#inicio">Inicio</a><span>/</span><span class="cur">{attrs["crumb"]}</span></nav>'
                f'<p class="ey">{attrs["ey"]}</p><h1>{attrs["h1"]}</h1><p class="lead">{attrs["lead"]}</p><div class="ctas">{ctas}</div></div>')
    return re.sub(r'<section class="page-hero"[^>]*>(?=</section>)', sub, seg)

def lazy(html_txt: str) -> str:
    def sub(m):
        tag = m.group(0)
        if 'loading=' in tag or 'fetchpriority' in tag or 'logo.webp' in tag:
            return tag
        return tag[:-1] + ' loading="lazy" decoding="async">'
    return re.sub(r'<img\b[^>]*>', sub, html_txt)

def texto_plano(h: str) -> str:
    return re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', h))).strip()

# ---------------------------------------------------------------- datos estructurados
NEGOCIO = {
    '@type': ['LocalBusiness', 'MedicalBusiness'], '@id': BASE + '#negocio',
    'name': 'Nutriendo Hábitos', 'alternateName': 'Nutriendo Hábitos · Andrea Argüello',
    'description': 'Consulta de dietética y nutrición en Valladolid y online: nutrición deportiva, nutrición oncológica, pérdida de peso, patología digestiva y educación nutricional.',
    'url': BASE, 'telephone': '+34 669 87 70 52', 'email': 'nutricion.arguello@gmail.com',
    'image': BASE + 'img/og.jpg', 'logo': BASE + 'img/logo.webp',
    'address': {'@type': 'PostalAddress', 'streetAddress': 'Calle Gregorio Fernández, 4', 'postalCode': '47006',
                'addressLocality': 'Valladolid', 'addressRegion': 'Castilla y León', 'addressCountry': 'ES'},
    'areaServed': [{'@type': 'City', 'name': 'Valladolid'}, {'@type': 'Country', 'name': 'España'}],
    'founder': {'@id': BASE + '#andrea'}, 'sameAs': ['https://www.instagram.com/nutrir_habitos/'],
    'medicalSpecialty': 'DietNutrition', 'hasMap': MAPS,
    'knowsAbout': ['Nutrición deportiva', 'Nutrición oncológica', 'Pérdida de peso', 'Patología digestiva', 'SIBO', 'Celiaquía', 'Alimentación vegetariana y vegana', 'Dietética', 'Bioimpedancia'],
}
ANDREA = {'@type': 'Person', '@id': BASE + '#andrea', 'name': 'Andrea Argüello Arbe', 'jobTitle': 'Dietista-nutricionista',
          'worksFor': {'@id': BASE + '#negocio'},
          'alumniOf': [{'@type': 'CollegeOrUniversity', 'name': 'Universidad de Valladolid'},
                       {'@type': 'CollegeOrUniversity', 'name': 'Universidad de Barcelona'}],
          'hasCredential': [
              {'@type': 'EducationalOccupationalCredential', 'credentialCategory': 'degree', 'name': 'Diplomatura en Nutrición Humana y Dietética', 'recognizedBy': {'@type': 'CollegeOrUniversity', 'name': 'Universidad de Valladolid'}},
              {'@type': 'EducationalOccupationalCredential', 'credentialCategory': 'Máster', 'name': 'Máster en Nutrición', 'recognizedBy': {'@type': 'CollegeOrUniversity', 'name': 'Universidad de Barcelona'}},
              {'@type': 'EducationalOccupationalCredential', 'credentialCategory': 'Máster', 'name': 'Máster en Nutrición Oncológica', 'dateCreated': '2026'}]}
WEB = {'@type': 'WebSite', '@id': BASE + '#web', 'url': BASE, 'name': 'Nutriendo Hábitos', 'inLanguage': 'es-ES', 'publisher': {'@id': BASE + '#negocio'}}

SERVICIOS = {
    'nutricion-deportiva-valladolid': 'Nutrición deportiva',
    'nutricion-oncologica-valladolid': 'Nutrición oncológica',
    'perdida-de-peso-valladolid': 'Consulta de nutrición para pérdida y ganancia de peso',
    'patologias-digestivas-valladolid': 'Nutrición en patologías digestivas',
    'alimentacion-vegetariana-vegana-valladolid': 'Asesoramiento en alimentación vegetariana y vegana',
    'dietista-nutricionista-en-valladolid': 'Consulta de dietética y nutrición',
    'nutricionista-online': 'Consulta de nutrición online',
}

def jsonld(slug: str, url: str, titulo: str, desc: str, seg: str) -> str:
    graph = [NEGOCIO, ANDREA, WEB,
             {'@type': 'WebPage', '@id': url + '#pagina', 'url': url, 'name': titulo, 'description': desc,
              'isPartOf': {'@id': BASE + '#web'}, 'about': {'@id': BASE + '#negocio'}, 'inLanguage': 'es-ES'}]
    if slug != 'inicio':
        crumb = re.search(r'data-crumb="([^"]+)"', seg)
        nombre = crumb.group(1) if crumb else titulo.split('|')[0].strip()
        graph.append({'@type': 'BreadcrumbList', 'itemListElement': [
            {'@type': 'ListItem', 'position': 1, 'name': 'Inicio', 'item': BASE},
            {'@type': 'ListItem', 'position': 2, 'name': nombre, 'item': url}]})
    if slug in SERVICIOS:
        graph.append({'@type': 'Service', 'name': SERVICIOS[slug], 'serviceType': SERVICIOS[slug], 'url': url,
                      'provider': {'@id': BASE + '#negocio'},
                      'areaServed': [{'@type': 'City', 'name': 'Valladolid'}, {'@type': 'Country', 'name': 'España'}],
                      'availableChannel': {'@type': 'ServiceChannel', 'serviceLocation': {'@id': BASE + '#negocio'}}})
    faqs = re.findall(r'<details><summary>(.*?)</summary><div class="oc small">(.*?)</div></details>', seg, re.S)
    if faqs:
        graph.append({'@type': 'FAQPage', 'mainEntity': [
            {'@type': 'Question', 'name': texto_plano(q), 'acceptedAnswer': {'@type': 'Answer', 'text': texto_plano(a)}} for q, a in faqs]})
    return '<script type="application/ld+json">' + json.dumps({'@context': 'https://schema.org', '@graph': graph}, ensure_ascii=False) + '</script>'

# ---------------------------------------------------------------- montar cada página
def nav_html(slug: str) -> str:
    activo = NAVMAP.get(slug, slug)
    out = []
    for key, txt in NAV:
        href = './' if key == 'inicio' else FILES[key]
        cur = ' aria-current="page"' if key == activo else ''
        out.append(f'<a href="{href}" data-nav="{key}"{cur}>{txt}</a>')
    return ''.join(out)

if OUT.exists():
    shutil.rmtree(OUT)
OUT.mkdir(parents=True)

for slug, (archivo, titulo, desc, _prio) in PAGINAS.items():
    url = BASE if archivo == 'index.html' else BASE + archivo
    seg = hero_estatico(segmentos[slug])
    robots = 'noindex, nofollow' if MODO == 'prueba' else ('noindex, follow' if slug == 'proteccion-de-datos' else 'index, follow, max-image-preview:large')
    head = f'''<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>{html.escape(titulo)}</title>
<meta name="description" content="{html.escape(desc)}">
<meta name="robots" content="{robots}">
<link rel="canonical" href="{url}">
<meta name="author" content="Andrea Argüello Arbe">
<meta property="og:type" content="website">
<meta property="og:locale" content="es_ES">
<meta property="og:site_name" content="Nutriendo Hábitos">
<meta property="og:title" content="{html.escape(titulo)}">
<meta property="og:description" content="{html.escape(desc)}">
<meta property="og:url" content="{url}">
<meta property="og:image" content="{BASE}img/og.jpg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#56704F">
<link rel="preload" as="image" href="img/{'hero' if slug == 'inicio' else re.search(r'data-hero="([^"]+)"', seg).group(1) if 'data-hero=' in seg else 'logo'}.webp">
{jsonld(slug, url, titulo, desc, seg)}
<style>:root{{padding-top:env(safe-area-inset-top);padding-bottom:env(safe-area-inset-bottom)}}body{{margin:0}}img{{max-width:100%}}[hidden]{{display:none!important}}</style>
'''
    extra = re.sub(r'<title>.*?</title>\n', '', head_extra, flags=re.S).replace('<meta name="claude:viewport-fit-sides" content="cover">\n', '')
    cuerpo = antes_main + '\n' + seg + '\n' + despues_main
    cuerpo = cuerpo.replace('<nav class="main" aria-label="Principal"></nav>', f'<nav class="main" aria-label="Principal">{nav_html(slug)}</nav>')
    cuerpo = cuerpo.replace('<nav class="mobile-nav" aria-label="Menú móvil"></nav>', f'<nav class="mobile-nav" aria-label="Menú móvil">{nav_html(slug)}</nav>')
    mpa = {'page': slug, 'files': FILES_JS, 'anchors': {k: v.replace('index.html#', './#') for k, v in ANCLAS.items()}}
    cuerpo = cuerpo.replace('<script>\n(function(){', '<script>window.__MPA=' + json.dumps(mpa, ensure_ascii=False) + ';</script>\n<script>\n(function(){', 1)
    cuerpo = reescribir_enlaces(cuerpo, slug)
    cuerpo = lazy(cuerpo)
    if slug == 'inicio':
        cuerpo = cuerpo.replace('<img src="img/hero.webp" alt="Bowl saludable con verduras y legumbres" loading="lazy" decoding="async">',
                                '<img src="img/hero.webp" alt="Bowl saludable con verduras y legumbres, consulta de nutricionista en Valladolid" fetchpriority="high">')
    (OUT / archivo).write_text(head + extra + '\n</head>\n<body>\n' + cuerpo.strip() + '\n</body>\n</html>\n', encoding='utf-8')

# ---------------------------------------------------------------- resto de archivos
shutil.copytree(SITE / 'img', OUT / 'img')
shutil.copy(SITE / 'enviar.php', OUT / 'enviar.php')
shutil.copytree(SITE / 'reservas', OUT / 'reservas', ignore=shutil.ignore_patterns('*.json.php', '.limite_*', '.cerrojo'))
admin = (OUT / 'reservas' / 'admin.php').read_text(encoding='utf-8').replace('href="../#reservar"', 'href="../reservar.html"')
(OUT / 'reservas' / 'admin.php').write_text(admin, encoding='utf-8')

# llms.txt: resumen en texto claro para ChatGPT, Claude, Perplexity y otros asistentes
LLMS = f"""# Nutriendo Hábitos · Andrea Argüello Arbe, nutricionista en Valladolid

> Consulta de dietética y nutrición en Valladolid (Calle Gregorio Fernández, 4, 47006) y online para toda España. Andrea Argüello Arbe es dietista-nutricionista desde 2013, diplomada en Nutrición Humana y Dietética (Universidad de Valladolid), con Máster en Nutrición (Universidad de Barcelona) y Máster en Nutrición Oncológica (2026). Miembro del Equipo Sputnik y de Sputnik Investigación (escalada).

Especialidades: nutrición deportiva (escalada, running, trail, ciclismo, fuerza), nutrición oncológica (antes, durante y después del tratamiento del cáncer), pérdida y ganancia de peso, patologías digestivas (SIBO, intestino irritable, dieta FODMAP, intolerancias, DAO, celiaquía, Crohn, reflujo), alimentación vegetariana y vegana, diabetes, TCA, alimentación infantil y educación nutricional. Composición corporal con bioimpedancia en cada consulta. Primera consulta de unos 75 minutos; revisiones de unos 45 minutos.

Contacto: teléfono y WhatsApp 669 87 70 52 · nutricion.arguello@gmail.com · reserva online de citas presenciales y por videollamada.

## Especialidades
- [Nutricionista en Valladolid: servicios y consulta]({BASE}dietista-nutricionista-en-valladolid.html)
- [Nutrición deportiva en Valladolid]({BASE}nutricion-deportiva-valladolid.html)
- [Nutrición oncológica en Valladolid y online]({BASE}nutricion-oncologica-valladolid.html)
- [Pérdida y ganancia de peso]({BASE}perdida-de-peso-valladolid.html)
- [Patologías digestivas: SIBO, intolerancias, celiaquía]({BASE}patologias-digestivas-valladolid.html)
- [Alimentación vegetariana y vegana]({BASE}alimentacion-vegetariana-vegana-valladolid.html)
- [Consulta de nutrición online]({BASE}nutricionista-online.html)

## Reservar y contactar
- [Reservar cita (calendario online)]({BASE}reservar.html)
- [Contacto y ubicación]({BASE}contacto.html)

## Más
- [Asesoría nutricional para colegios, comedores y empresas]({BASE}asesoria-externa.html)
- [Cursos y talleres de nutrición]({BASE}cursos-talleres.html)
- [Blog de nutrición]({BASE}blog-nutricion.html)
"""
(OUT / 'llms.txt').write_text(LLMS, encoding='utf-8')

# Página de error 404 (rutas absolutas: se muestra en cualquier dirección que no exista)
(OUT / '404.html').write_text((SITE / '404.tpl.html').read_text(encoding='utf-8').replace('{{RAIZ}}', RAIZ), encoding='utf-8')
(OUT / '.htaccess').write_text(f'''# Nutriendo Hábitos
ErrorDocument 404 {RAIZ}404.html

# Comprimir texto (páginas más rápidas)
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/css text/plain text/xml application/javascript application/json image/svg+xml
</IfModule>

# Guardar en caché imágenes y archivos que no cambian
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType image/webp "access plus 1 month"
  ExpiresByType image/jpeg "access plus 1 month"
  ExpiresByType image/png "access plus 1 month"
  ExpiresByType application/javascript "access plus 1 month"
  ExpiresByType application/wasm "access plus 1 month"
  ExpiresByType text/html "access plus 0 seconds"
</IfModule>
''', encoding='utf-8')

if MODO == 'definitiva':
    # Una sola dirección para Google: sin «www» redirige a https://www.nutriendohabitos.es (la que usan canonical y sitemap)
    ht = (OUT / '.htaccess').read_text(encoding='utf-8')
    (OUT / '.htaccess').write_text('''<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{HTTP_HOST} ^nutriendohabitos\\.es$ [NC]
  RewriteRule ^ https://www.nutriendohabitos.es%{REQUEST_URI} [L,R=301]
</IfModule>

''' + ht, encoding='utf-8')
    urls = ''.join(f'  <url><loc>{BASE if a == "index.html" else BASE + a}</loc><lastmod>{HOY}</lastmod><priority>{p}</priority></url>\n'
                   for s_, (a, t, d, p) in PAGINAS.items() if s_ != 'proteccion-de-datos')
    (OUT / 'sitemap.xml').write_text('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' + urls + '</urlset>\n', encoding='utf-8')
    (OUT / 'robots.txt').write_text(f'User-agent: *\nAllow: /\nDisallow: /reservas/\nDisallow: /nueva/\nDisallow: /NUEVA/\n\nSitemap: {BASE}sitemap.xml\n', encoding='utf-8')

print(MODO, '->', OUT, '|', len(PAGINAS), 'páginas')
