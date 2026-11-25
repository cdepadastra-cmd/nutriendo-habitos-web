# -*- coding: utf-8 -*-
"""
Borradores de artículos (sin publicar). Genera ../borradores.html, que build.py convierte en páginas
dentro de una carpeta oculta (noindex, sin enlaces desde la web). Uso: python3 articulos.py
Para publicar un artículo: pasar su bloque a nutriendo-habitos.html y darlo de alta en build.py.
"""
import json, html
from pathlib import Path

AQUI = Path(__file__).resolve().parent
NUT = json.load(open(AQUI.parent / 'datos-alimentos' / 'nutrientes.json', encoding='utf-8'))

def faq(items, ident):
    q = ''.join(f'<details><summary>{a}</summary><div class="oc small"><p>{b}</p></div></details>' for a, b in items)
    return f'<section class="faq" aria-labelledby="{ident}"><h2 class="bar-h2" id="{ident}">Preguntas frecuentes</h2><div class="faq-list">{q}</div></section>'

def stmts(items):
    return '<div class="lead-rule"></div><div>' + ''.join(
        f'<article class="stmt"><div class="left"><span class="card-icon sq" data-ic2="{ic}"></span><h3 class="card-title">{t}</h3></div><div class="oc small">{b}</div></article>'
        for ic, t, b in items) + '</div>'

def banda(titulo, cuerpo):
    return f'<div class="band" style="margin-top:0"><h2>{titulo}</h2><div class="oc" style="max-width:42rem">{cuerpo}</div></div>'

ARTS = []
def art(slug, titulo, desc, hero, crumb, ey, h1, lead, cuerpo, nxt='servicios,blog,online'):
    ARTS.append((slug, titulo, desc, f'''<div data-page="{slug}" data-titulo="{html.escape(titulo)}" data-desc="{html.escape(desc)}" hidden>
  <section class="page-hero" data-hero="{hero}" data-crumb="{crumb}" data-ey="{ey}" data-h1="{h1}" data-lead="{lead}"></section>
  <div class="wrap editorial">
{cuerpo}
  </div>
  <div data-cta data-next="{nxt}"></div>
</div>
'''))

# ------------------------------------------------------------ alimentos ricos en… (tablas desde nutrientes.json)
COL = {'hierro': (10, 'mg', 1, 14), 'b12': (4, 'µg', 2, 2.5), 'calcio': (11, 'mg', 0, 800), 'fibra': (13, 'g', 1, 25), 'omega3': (9, 'mg', 0, 250), 'vitc': (6, 'mg', 0, 80)}
def fmt(v, d):
    return f'{v:,.{d}f}'.replace(',', 'X').replace('.', ',').replace('X', '.')
def tabla(clave, n=15, nombre=''):
    i, u, d, vrn = COL[clave]
    filas = [a for a in NUT['a'] if a[i] is not None and a[i] > 0 and 'enriquecid' not in a[0]]
    por100 = sorted(filas, key=lambda a: -a[i])[:n]
    porrac = sorted(filas, key=lambda a: -a[i] * a[14] / 100)[:n]
    t1 = ''.join(f'<tr><td>{a[0]}</td><td>{fmt(a[i], d)} {u}</td></tr>' for a in por100)
    t2 = ''.join(f'<tr><td>{a[0]} <small>({a[14]} g, {a[15]})</small></td><td>{fmt(a[i]*a[14]/100, d)} {u}</td><td>{round(100*a[i]*a[14]/100/vrn)} %</td></tr>' for a in porrac)
    return f'''<div class="band"><h2>Los alimentos con más {nombre}</h2><div class="oc" style="max-width:46rem">
<p>Ordenados por cantidad. Por 100 g destacan alimentos muy concentrados; <strong>por ración</strong> se ve mejor lo que de verdad aporta cada uno en un plato normal.</p>
<h3>Por 100 g</h3><div class="nt-wrap"><table class="tl-tab cv-tab"><thead><tr><th>Alimento</th><th>Por 100 g</th></tr></thead><tbody>{t1}</tbody></table></div>
<h3 style="margin-top:1.5rem">Por ración habitual</h3><div class="nt-wrap"><table class="tl-tab cv-tab"><thead><tr><th>Alimento (ración)</th><th>Por ración</th><th>% diario*</th></tr></thead><tbody>{t2}</tbody></table></div>
<p class="tl-src">*Porcentaje de la cantidad diaria de referencia para adultos ({fmt(vrn, 1 if vrn < 10 else 0)} {u}). Datos: USDA FoodData Central (SR Legacy). <a href="#alimentos-ricos-en-proteinas-vitaminas">Ver la tabla completa y ordenarla por otros nutrientes</a>.</p></div></div>'''

art('alimentos-ricos-en-hierro', 'Alimentos ricos en hierro: tabla y cómo absorberlo mejor | Nutriendo Hábitos',
    'Qué alimentos tienen más hierro por 100 g y por ración, cuánto hierro necesitas al día y trucos para absorber mejor el hierro de las legumbres y verduras.',
    'tips', 'Alimentos ricos en hierro', 'Guía de nutrientes', 'Alimentos ricos en hierro: tabla y cómo absorberlo mejor',
    'Qué alimentos aportan más hierro, cuánto necesitas al día y cómo combinarlos para que tu cuerpo lo aproveche, también si no comes carne.',
    banda('Para qué sirve el hierro y cuánto necesitas', '<p>El hierro forma parte de la hemoglobina, que lleva el oxígeno por la sangre. Cuando falta aparecen cansancio, palidez, uñas frágiles, caída de pelo o falta de concentración, y con el tiempo, <strong>anemia</strong>.</p><p>La EFSA recomienda unos <strong>11 mg al día</strong> para hombres y mujeres tras la menopausia, y <strong>16 mg</strong> para mujeres en edad fértil, que pierden hierro con la regla. En el embarazo las necesidades suben y suele pautarse un suplemento.</p>')
    + tabla('hierro', nombre='hierro') + stmts([
    ('heart', 'Hierro hemo y no hemo', '<p>El hierro de la <strong>carne, el hígado, el pescado y el marisco</strong> (hierro hemo) se absorbe mucho mejor que el de las legumbres, los cereales, los frutos secos y las verduras (hierro no hemo). Eso no quiere decir que el vegetal no cuente: solo hay que ayudarle.</p>'),
    ('apple', 'Trucos para absorber más', '<ul><li>Acompaña las legumbres y verduras con <strong>vitamina C</strong>: pimiento, tomate, perejil, cítricos o un kiwi de postre.</li><li>Deja el <strong>café y el té</strong> para una hora después de comer: reducen la absorción.</li><li>Si tomas un suplemento de calcio, no lo hagas en la misma comida que tu fuente principal de hierro.</li><li>Remojar, germinar o fermentar las legumbres también mejora su aprovechamiento.</li></ul>'),
    ('stetho', 'Antes de tomar suplementos', '<p>No tomes hierro por tu cuenta: el exceso también es perjudicial y el cansancio puede tener otras causas. Lo primero es una <strong>analítica</strong> (hemoglobina y ferritina) y una pauta de tu médico.</p>'),
    ]) + faq([
    ('¿Qué alimento tiene más hierro?', 'Por 100 g, el sésamo, la soja texturizada, las semillas de calabaza y de chía, los frutos secos y las legumbres secas. Por ración, las alubias, las lentejas, los garbanzos, el pulpo y el hígado.'),
    ('¿Las espinacas tienen mucho hierro?', 'Tienen una cantidad moderada y se absorbe poco. Las legumbres aportan bastante más. La fama de las espinacas viene más de los dibujos animados que de la ciencia.'),
    ('¿Qué comer si tengo anemia?', 'Incluye a diario fuentes de hierro (carne, pescado, marisco, huevos, legumbres) acompañadas de vitamina C, y sigue el tratamiento que te paute tu médico. La dieta sola rara vez corrige una anemia ya establecida.'),
    ], 'faq-fe'), 'nutrientes,vegetariana,servicios')

art('alimentos-ricos-en-vitamina-b12', 'Alimentos ricos en vitamina B12: tabla y quién necesita suplemento | Nutriendo Hábitos',
    'Qué alimentos tienen más vitamina B12, cuánta necesitas al día, síntomas de déficit y quién debe tomar suplemento (veganos, mayores, algunos medicamentos).',
    'tips', 'Alimentos ricos en vitamina B12', 'Guía de nutrientes', 'Alimentos ricos en vitamina B12 y quién necesita suplemento',
    'La vitamina B12 solo está de forma fiable en alimentos de origen animal. Te contamos cuáles tienen más, cuánta necesitas y cuándo hace falta suplementarla.',
    banda('Para qué sirve la B12 y cuánta necesitas', '<p>La vitamina B12 es necesaria para el <strong>sistema nervioso</strong> y para fabricar glóbulos rojos. Su déficit puede dar cansancio, anemia, hormigueos en manos y pies, problemas de memoria o de ánimo, y si se prolonga, daños neurológicos que no siempre se recuperan.</p><p>La EFSA considera adecuada una ingesta de <strong>4 µg al día</strong> en adultos; la cantidad de referencia del etiquetado es 2,5 µg.</p>')
    + tabla('b12', nombre='vitamina B12') + stmts([
    ('leaf', 'Si eres vegano', '<p>Las algas, la espirulina, el tempeh o la levadura <strong>no son fuentes fiables</strong> de B12 activa. Si sigues una alimentación vegana necesitas un <strong>suplemento de vitamina B12</strong> (cianocobalamina) siempre, no solo cuando sale baja en la analítica. Hay pautas diarias o semanales; tu dietista-nutricionista te indicará la tuya. Las personas ovolactovegetarianas también suelen necesitarlo.</p>'),
    ('stetho', 'Otras personas en riesgo', '<ul><li><strong>Mayores de 50-60 años</strong>: con la edad se absorbe peor.</li><li>Personas que toman <strong>metformina</strong> o <strong>protectores de estómago</strong> (omeprazol y similares) durante mucho tiempo.</li><li>Tras cirugías de estómago o intestino, con gastritis atrófica, celiaquía o enfermedad de Crohn.</li></ul><p>En estos casos conviene revisarla en la analítica.</p>'),
    ]) + faq([
    ('¿Qué alimento tiene más vitamina B12?', 'El hígado, las almejas, los mejillones, el pulpo, las sardinas y la caballa. Una sola ración de cualquiera de ellos supera con creces lo que necesitas en un día.'),
    ('¿Los huevos y la leche tienen B12?', 'Sí, en cantidades más modestas: dos huevos o un par de vasos de leche aportan una parte importante de lo que necesitas, pero en una dieta ovolactovegetariana suele quedarse corto.'),
    ('¿Se puede tomar demasiada B12?', 'No se ha establecido un límite máximo porque el exceso se elimina por la orina. Aun así, la dosis del suplemento debe ajustarse a tu caso.'),
    ], 'faq-b12'), 'nutrientes,vegetariana,servicios')

art('alimentos-ricos-en-calcio', 'Alimentos ricos en calcio (con y sin lácteos): tabla | Nutriendo Hábitos',
    'Qué alimentos tienen más calcio, con y sin lácteos, cuánto necesitas al día y cómo cuidar tus huesos con la alimentación, la vitamina D y el ejercicio.',
    'tips', 'Alimentos ricos en calcio', 'Guía de nutrientes', 'Alimentos ricos en calcio, con y sin lácteos',
    'Los lácteos no son la única fuente de calcio. Descubre qué alimentos tienen más, cuánto necesitas y qué más hace falta para tener unos huesos fuertes.',
    banda('Para qué sirve el calcio y cuánto necesitas', '<p>El 99 % del calcio del cuerpo está en los <strong>huesos y los dientes</strong>, pero también hace falta para que los músculos se contraigan, el corazón lata y la sangre coagule.</p><p>La EFSA recomienda unos <strong>950 mg al día</strong> en adultos a partir de 25 años y 1.000 mg entre los 18 y los 24. Con la menopausia y en mayores, cuidar el aporte es especialmente importante.</p>')
    + tabla('calcio', nombre='calcio') + stmts([
    ('leaf', 'Calcio sin lácteos', '<ul><li><strong>Tofu</strong> cuajado con sales de calcio (míralo en los ingredientes).</li><li><strong>Bebidas vegetales enriquecidas</strong> en calcio.</li><li><strong>Sardinas y boquerones</strong> en lata, con la espina.</li><li><strong>Almendras, sésamo</strong> (y tahini), higos secos.</li><li><strong>Legumbres</strong> y verduras de hoja como el brócoli, la col rizada o el repollo. Las espinacas y acelgas tienen calcio, pero se absorbe poco.</li></ul>'),
    ('activity', 'No solo es calcio', '<p>Para que el calcio llegue al hueso hace falta <strong>vitamina D</strong> (sol y pescado azul), suficiente <strong>proteína</strong> y, sobre todo, <strong>ejercicio de fuerza e impacto</strong>: caminar, subir escaleras, saltar, entrenar con pesas. El tabaco, el alcohol y el exceso de sal juegan en contra.</p>'),
    ]) + faq([
    ('¿Qué alimento tiene más calcio?', 'Por 100 g, los quesos curados, el sésamo, el tofu, las semillas de chía y las sardinas en lata con espina. Por ración, un vaso de leche, un yogur, un trozo de queso o una lata de sardinas aportan cada uno entre un cuarto y un tercio de lo que necesitas al día.'),
    ('¿Cuántos lácteos hay que tomar al día?', 'Las guías españolas recomiendan 2-3 raciones de lácteos al día. Si no tomas lácteos, combina varias de las fuentes vegetales y enriquecidas.'),
    ('¿Hay que tomar suplementos de calcio en la menopausia?', 'Solo si con la alimentación no llegas, y siempre con indicación profesional. Primero, revisa tu dieta y tu vitamina D.'),
    ], 'faq-ca'), 'nutrientes,peso,servicios')

art('alimentos-ricos-en-fibra', 'Alimentos ricos en fibra: tabla y cómo llegar a 25 g al día | Nutriendo Hábitos',
    'Qué alimentos tienen más fibra, cuánta necesitas al día, para qué sirve y cómo aumentarla sin hinchazón. Tabla por 100 g y por ración.',
    'tips', 'Alimentos ricos en fibra', 'Guía de nutrientes', 'Alimentos ricos en fibra y cómo llegar a 25 g al día',
    'La mayoría de las personas toma mucha menos fibra de la recomendada. Descubre qué alimentos tienen más y cómo aumentarla poco a poco.',
    banda('Para qué sirve la fibra y cuánta necesitas', '<p>La fibra <strong>sacia</strong>, regula el tránsito intestinal, alimenta a la <strong>microbiota</strong>, ayuda a controlar la glucosa y el colesterol y se asocia a menos riesgo de enfermedad cardiovascular y de cáncer de colon.</p><p>La EFSA recomienda al menos <strong>25 g al día</strong> en adultos; la media en España se queda bastante por debajo.</p>')
    + tabla('fibra', nombre='fibra') + stmts([
    ('salad', 'Cómo llegar a 25 g', '<ul><li><strong>Legumbres</strong> 3-4 veces por semana (un plato aporta 10-15 g).</li><li>Pan, pasta y arroz <strong>integrales</strong>.</li><li><strong>Fruta entera</strong> en lugar de zumo, mejor con piel.</li><li>Verdura en comida y cena.</li><li>Un puñado de <strong>frutos secos</strong> o una cucharada de semillas.</li></ul>'),
    ('drop', 'Sin hinchazón', '<p>Aumenta la fibra <strong>poco a poco</strong>, a lo largo de 2-3 semanas, y <strong>bebe agua</strong> suficiente. Si tienes intestino irritable o SIBO, algunos alimentos ricos en fibra pueden sentarte mal: en ese caso conviene una pauta individual. <a href="#dieta-fodmap">Guía FODMAP</a>.</p>'),
    ]) + faq([
    ('¿Qué alimento tiene más fibra?', 'Por 100 g, las semillas de chía y lino, la soja texturizada, el coco, las almendras y las legumbres secas. Por ración, un plato de lentejas, garbanzos o alubias es de lo que más aporta.'),
    ('¿La fibra ayuda al estreñimiento?', 'Sí, junto con agua y actividad física. Los kiwis, las ciruelas pasas y las semillas de lino o el psyllium son especialmente útiles.'),
    ], 'faq-fib'), 'nutrientes,digestiva,servicios')

art('alimentos-ricos-en-omega-3', 'Alimentos ricos en omega 3: pescados y fuentes vegetales | Nutriendo Hábitos',
    'Qué pescados y alimentos vegetales tienen más omega 3 (EPA, DHA y ALA), cuánto necesitas, cuánto pescado azul comer a la semana y qué pescados evitar en el embarazo.',
    'tips', 'Alimentos ricos en omega 3', 'Guía de nutrientes', 'Alimentos ricos en omega 3: pescados y fuentes vegetales',
    'El omega 3 del pescado azul (EPA y DHA) y el de las semillas y nueces (ALA) no son iguales. Te contamos dónde está cada uno y cuánto necesitas.',
    banda('Para qué sirve el omega 3 y cuánto necesitas', '<p>Los ácidos grasos omega 3 forman parte de las membranas de las células, sobre todo en el <strong>cerebro y la retina</strong>, y el EPA y el DHA ayudan al funcionamiento normal del <strong>corazón</strong>.</p><p>La EFSA considera adecuados <strong>250 mg al día de EPA + DHA</strong> en adultos (más en el embarazo y la lactancia). Se consigue comiendo <strong>pescado azul una o dos veces por semana</strong>.</p>')
    + tabla('omega3', nombre='omega 3 EPA + DHA') + stmts([
    ('leaf', 'Omega 3 vegetal (ALA)', '<p>Las <strong>semillas de lino y chía, las nueces, la soja</strong> y el aceite de lino aportan ALA. El cuerpo convierte solo una pequeña parte en EPA y DHA, así que en dietas veganas conviene valorar un suplemento de <strong>aceite de algas</strong>. Toma el lino molido para aprovecharlo.</p>'),
    ('utensils', 'Qué pescado elegir', '<p>Los pescados azules <strong>pequeños</strong> (sardina, boquerón, caballa, jurel) tienen mucho omega 3 y poco mercurio. La AESAN recomienda que embarazadas, mujeres en lactancia y niños menores de 10 años <strong>eviten el pez espada, el tiburón, el atún rojo y el lucio</strong>.</p>'),
    ]) + faq([
    ('¿Qué pescado tiene más omega 3?', 'La caballa, el salmón, los boquerones, las sardinas y la trucha.'),
    ('¿Hay que tomar cápsulas de omega 3?', 'Si comes pescado azul una o dos veces por semana, normalmente no. Puede valorarse en dietas veganas, en algunas enfermedades o por indicación médica.'),
    ('¿Las latas de sardinas o caballa sirven?', 'Sí, conservan bien el omega 3 y son una opción práctica y barata. Escúrrelas si están en aceite de girasol y mira la sal.'),
    ], 'faq-om3'), 'nutrientes,deportiva,servicios')

art('alimentos-ricos-en-vitamina-c', 'Alimentos ricos en vitamina C: más que la naranja | Nutriendo Hábitos',
    'Qué frutas y verduras tienen más vitamina C, cuánta necesitas al día, para qué sirve y cómo cocinar para no perderla. Tabla por 100 g y por ración.',
    'tips', 'Alimentos ricos en vitamina C', 'Guía de nutrientes', 'Alimentos ricos en vitamina C: mucho más que la naranja',
    'El pimiento, el kiwi o el brócoli superan a la naranja. Descubre cuáles tienen más vitamina C y cómo conservarla al cocinar.',
    banda('Para qué sirve la vitamina C y cuánta necesitas', '<p>La vitamina C es antioxidante, necesaria para formar <strong>colágeno</strong> (piel, encías, vasos sanguíneos), para las <strong>defensas</strong> y para absorber el <strong>hierro</strong> de los vegetales.</p><p>La EFSA recomienda <strong>95 mg al día</strong> en mujeres y <strong>110 mg</strong> en hombres. Con 5 raciones de fruta y verdura al día se cubre de sobra.</p>')
    + tabla('vitc', nombre='vitamina C') + stmts([
    ('chef', 'Cómo no perderla al cocinar', '<ul><li>Toma parte de la fruta y la verdura <strong>en crudo</strong>.</li><li>Cocina al <strong>vapor</strong>, al microondas o salteado y poco tiempo, mejor que hervido largo.</li><li>Corta justo antes de comer y aprovecha el caldo de cocción.</li></ul>'),
    ('heart', '¿Previene los resfriados?', '<p>Tomar suplementos de vitamina C no evita los resfriados en la población general; como mucho, acorta ligeramente su duración en algunas personas. Lo que sí funciona es comer fruta y verdura a diario.</p>'),
    ]) + faq([
    ('¿Qué fruta tiene más vitamina C?', 'Entre las habituales, el kiwi, la papaya, las fresas y los cítricos. Un kiwi cubre prácticamente lo que necesitas en un día.'),
    ('¿Qué verdura tiene más vitamina C?', 'El pimiento (sobre todo el rojo), la col rizada, el brócoli, las coles de Bruselas y la coliflor.'),
    ], 'faq-vc'), 'nutrientes,temporada,servicios')

# ------------------------------------------------------------ menopausia
art('alimentacion-menopausia', 'Alimentación en la menopausia: qué comer y nutricionista en Valladolid | Nutriendo Hábitos',
    'Cómo adaptar la alimentación en la menopausia: peso y grasa abdominal, músculo, huesos, corazón, sofocos y sueño. Consejos de una dietista-nutricionista en Valladolid.',
    'hero', 'Alimentación en la menopausia', 'Salud de la mujer', 'Alimentación en la menopausia: qué cambia y qué comer',
    'Con la menopausia cambian el peso, el músculo, los huesos y el riesgo cardiovascular. La alimentación y el ejercicio son tus mejores aliados.',
    banda('Qué cambia en la menopausia', '<p>Al bajar los estrógenos, muchas mujeres notan que <strong>engordan con más facilidad</strong>, sobre todo en la <strong>tripa</strong>, aunque coman igual. También se pierde <strong>masa muscular y ósea</strong> más rápido, sube el colesterol y aumenta el riesgo cardiovascular. A esto se suman sofocos, peor descanso y cambios de ánimo.</p><p>No es una enfermedad, pero sí un buen momento para revisar cómo comes y cómo te mueves.</p>')
    + stmts([
    ('scale', 'Peso y grasa abdominal', '<p>Más que hacer dieta, funciona ajustar <strong>cantidades</strong>, priorizar <strong>verdura, legumbres, proteína y fibra</strong>, reducir ultraprocesados, azúcar y alcohol, y moverte más. Las dietas muy restrictivas hacen perder músculo, justo lo que más necesitas conservar.</p>'),
    ('activity', 'Músculo: proteína y fuerza', '<p>Reparte la <strong>proteína</strong> en todas las comidas (huevos, pescado, legumbres, lácteos, carne) y haz <strong>ejercicio de fuerza</strong> 2-3 días por semana. Es la combinación que mejor protege el músculo y el metabolismo. <a href="#calculadora-proteinas">Calcula tu proteína</a>.</p>'),
    ('heart', 'Huesos y corazón', '<p>Asegura <strong>calcio</strong> (lácteos o alternativas enriquecidas, sardinas, almendras, tofu), <strong>vitamina D</strong> (sol y pescado azul) y ejercicio de impacto. Para el corazón: aceite de oliva, pescado azul, frutos secos, legumbres, cereales integrales y menos sal. <a href="#alimentos-ricos-en-calcio">Alimentos ricos en calcio</a>.</p>'),
    ('leaf', 'Sofocos y sueño', '<p>En algunas mujeres el <strong>alcohol, la cafeína, el picante</strong> o las comidas muy calientes desencadenan sofocos: observa si es tu caso. Las <strong>isoflavonas de soja</strong> pueden aliviar algo en algunas mujeres; consulta antes de tomar suplementos. Cenar ligero y pronto ayuda a dormir mejor.</p>'),
    ('stetho', 'Cómo te ayudo en consulta', '<p>Medimos tu composición corporal con bioimpedancia para ver músculo y grasa, revisamos tu analítica y diseñamos una pauta realista, sin prohibiciones, adaptada a tus síntomas y a tu ritmo de vida. En Valladolid o <a href="#nutricionista-online">online</a>.</p>'),
    ]) + faq([
    ('¿Por qué engordo en la menopausia si como lo mismo?', 'Porque baja el gasto de energía, se pierde músculo y cambia el lugar donde se acumula la grasa. Ajustar la alimentación y sumar ejercicio de fuerza contrarresta buena parte de esos cambios.'),
    ('¿Qué alimentos son buenos para la menopausia?', 'Legumbres, verduras, frutas, pescado azul, lácteos o alternativas enriquecidas, frutos secos, semillas, aceite de oliva y cereales integrales. No hay un alimento milagro: cuenta el conjunto.'),
    ('¿Sirven los suplementos para la menopausia?', 'Algunos, como la vitamina D o el calcio, pueden hacer falta en casos concretos; otros, como las isoflavonas, tienen un efecto modesto. Mejor tomarlos con indicación profesional.'),
    ('¿Hay nutricionista para la menopausia en Valladolid?', 'Sí. Andrea Argüello, dietista-nutricionista colegiada, atiende en la calle Gregorio Fernández, 4, junto al Campo Grande, y también online.'),
    ], 'faq-meno'), 'servicios,prot,online')

# ------------------------------------------------------------ precios
art('precio-nutricionista-valladolid', 'Precio de un nutricionista en Valladolid: cuánto cuesta la consulta | Nutriendo Hábitos',
    'Cuánto cuesta la consulta de nutrición con Andrea Argüello en Valladolid y online: primera consulta, revisiones, qué incluye cada una y cómo pedir cita.',
    'contacto', 'Precios', 'Tarifas', 'Precio de la consulta de nutricionista en Valladolid',
    'Qué incluye cada consulta, cuánto dura y cuánto cuesta, presencial en Valladolid u online.',
    '<p class="tk-aviso tl-src" style="margin:0 0 1.5rem"><strong>PENDIENTE DE ANDREA:</strong> rellenar los precios marcados con [PRECIO] y confirmar duraciones y formas de pago antes de publicar.</p>'
    + banda('Tarifas', '<div class="nt-wrap"><table class="tl-tab cv-tab"><thead><tr><th>Consulta</th><th>Duración</th><th>Precio</th></tr></thead><tbody><tr><td>Primera consulta (presencial u online)</td><td>unos 75 min</td><td>[PRECIO]</td></tr><tr><td>Revisión</td><td>unos 45 min</td><td>[PRECIO]</td></tr><tr><td>[Bono de revisiones, si lo hay]</td><td>—</td><td>[PRECIO]</td></tr></tbody></table></div><p class="tl-src">[Formas de pago: efectivo, tarjeta, Bizum…]</p>')
    + stmts([
    ('clip', 'Qué incluye la primera consulta', '<ul><li>Historia clínica y de hábitos: qué comes, cómo duermes, actividad, analíticas, medicación.</li><li><strong>Composición corporal con bioimpedancia</strong> (grasa, músculo, agua) y medidas.</li><li>Objetivos realistas y una <strong>pauta personalizada</strong>, sin dietas cerradas imposibles de seguir.</li><li>Material de apoyo: menús, recetas, lista de la compra.</li></ul>'),
    ('cal', 'Revisiones', '<p>En cada revisión volvemos a medir la composición corporal, vemos cómo te has encontrado y ajustamos la pauta. La frecuencia habitual es cada 2-4 semanas al principio y más espaciadas después.</p>'),
    ('video', 'Consulta online', '<p>Por videollamada, con la misma duración y seguimiento. Ideal si no vives en Valladolid o te cuesta desplazarte. <a href="#nutricionista-online">Cómo funciona</a>.</p>'),
    ]) + faq([
    ('¿Cuánto cuesta un nutricionista en Valladolid?', 'En la consulta de Andrea Argüello, la primera consulta cuesta [PRECIO] y las revisiones [PRECIO]. Incluyen medición de composición corporal y pauta personalizada.'),
    ('¿La consulta de nutrición la cubre la Seguridad Social?', 'En la sanidad pública no suele haber dietistas-nutricionistas en atención primaria, por lo que la consulta es privada. [Confirmar si se emite factura para seguros o desgravaciones.]'),
    ('¿Cómo pido cita?', 'Por el formulario de contacto, por WhatsApp o llamando al 669 87 70 52.'),
    ], 'faq-precio'), 'servicios,online,blog')

# ------------------------------------------------------------ digestivo
DIG = 'digestiva,fodmap,sibo'
art('dieta-gastritis', 'Dieta para la gastritis: qué comer y qué evitar | Nutriendo Hábitos',
    'Qué comer si tienes gastritis, qué alimentos y hábitos pueden irritar el estómago y cuándo acudir al médico. Guía práctica de una dietista-nutricionista.',
    'tips', 'Dieta para la gastritis', 'Salud digestiva', 'Dieta para la gastritis: qué comer y qué evitar',
    'Consejos prácticos para cuidar el estómago cuando está irritado, sin dietas innecesariamente estrictas.',
    banda('Qué es la gastritis', '<p>Es la inflamación de la mucosa del estómago. Puede dar ardor, dolor en la boca del estómago, náuseas o sensación de llenarse enseguida. Sus causas más frecuentes son la bacteria <strong><em>Helicobacter pylori</em></strong> y los <strong>antiinflamatorios</strong> (ibuprofeno, aspirina…), además del alcohol y el tabaco.</p><p>La alimentación <strong>no cura la gastritis</strong>, pero sí ayuda a aliviar los síntomas mientras se trata la causa. Por eso lo primero es el diagnóstico de tu médico.</p>')
    + stmts([
    ('utensils', 'Hábitos que ayudan', '<ul><li>Comidas <strong>pequeñas y frecuentes</strong>, sin llegar con mucha hambre.</li><li>Comer despacio y masticar bien.</li><li>Cenar al menos 2-3 horas antes de acostarte.</li><li>Evitar el alcohol y el tabaco.</li><li>No tomar antiinflamatorios por tu cuenta.</li></ul>'),
    ('apple', 'Alimentos que suelen sentar bien', '<p>Arroz, pasta, patata, pan tostado, verduras cocidas, frutas no ácidas (plátano, manzana, pera), huevo, pescado y carnes magras a la plancha o al vapor, yogur natural y aceite de oliva en crudo.</p>'),
    ('heart', 'Lo que puede molestar', '<p>No hace falta eliminarlo todo: depende de cada persona. Los que más suelen molestar son el <strong>café</strong> (también el descafeinado), el <strong>alcohol</strong>, el <strong>picante</strong>, los fritos y las comidas muy grasas, el chocolate, los cítricos y el tomate crudo en algunas personas, y las bebidas con gas. Prueba y observa.</p>'),
    ('stetho', 'Cuándo ir al médico', '<p>Consulta sin esperar si vomitas sangre o algo parecido a posos de café, tienes heces negras, pierdes peso sin motivo, te cuesta tragar o los síntomas no mejoran.</p>'),
    ]) + faq([
    ('¿Qué puedo desayunar con gastritis?', 'Por ejemplo, tostadas con aceite y jamón cocido o pavo, un yogur natural con plátano o copos de avena con leche. Mejor sin café, o con poco si lo toleras.'),
    ('¿La leche es buena para la gastritis?', 'Calma momentáneamente, pero después estimula más ácido. No hace falta quitarla, pero no la uses como remedio.'),
    ('¿Cuánto dura una gastritis?', 'Depende de la causa. Tratada la causa (por ejemplo, el Helicobacter), suele mejorar en semanas.'),
    ], 'faq-gas'), DIG)

art('dieta-estrenimiento', 'Dieta para el estreñimiento: alimentos y trucos que funcionan | Nutriendo Hábitos',
    'Qué comer para ir mejor al baño: fibra, agua, kiwis, ciruelas, semillas de lino, horarios y postura. Y cuándo consultar con el médico.',
    'tips', 'Dieta para el estreñimiento', 'Salud digestiva', 'Dieta para el estreñimiento: qué comer y trucos que funcionan',
    'Alimentos y hábitos con respaldo científico para mejorar el tránsito intestinal sin abusar de laxantes.',
    banda('Por qué aparece el estreñimiento', '<p>Lo más habitual es la combinación de <strong>poca fibra, poca agua y poco movimiento</strong>, junto con aguantar las ganas por prisas o vergüenza. También influyen algunos medicamentos (hierro, opioides, antidepresivos), el embarazo, el hipotiroidismo o el intestino irritable.</p>')
    + stmts([
    ('salad', 'Fibra, poco a poco', '<p>Apunta a <strong>25-30 g de fibra al día</strong>: legumbres, verdura en comida y cena, fruta entera, cereales integrales, frutos secos y semillas. Aumenta poco a poco para evitar gases. <a href="#alimentos-ricos-en-fibra">Alimentos ricos en fibra</a>.</p>'),
    ('apple', 'Los que mejor funcionan', '<ul><li><strong>Kiwi</strong>: 2 al día han demostrado mejorar el tránsito.</li><li><strong>Ciruelas pasas</strong> (unas 4-6) o su zumo.</li><li><strong>Semillas de lino</strong> molidas o <strong>psyllium</strong>, siempre con un vaso grande de agua.</li></ul>'),
    ('drop', 'Agua y movimiento', '<p>Sin agua, la fibra puede empeorar el estreñimiento. Bebe a lo largo del día y muévete: caminar 30 minutos al día ya ayuda. <a href="#calculadora-agua">¿Cuánta agua necesitas?</a></p>'),
    ('home', 'Horario y postura', '<p>Intenta ir al baño a la misma hora, mejor después del desayuno, sin prisas. Apoyar los pies en un <strong>taburete</strong> (rodillas por encima de la cadera) facilita la evacuación.</p>'),
    ('stetho', 'Cuándo consultar', '<p>Si hay sangre en las heces, pérdida de peso, dolor intenso, alternancia con diarrea o es un cambio reciente a partir de los 50 años, acude a tu médico.</p>'),
    ]) + faq([
    ('¿Qué alimentos estriñen?', 'Más que alimentos concretos, estriñe una dieta pobre en fibra y rica en ultraprocesados, harinas refinadas y poca agua. El plátano poco maduro y el arroz blanco en exceso pueden empeorarlo en algunas personas.'),
    ('¿Es malo tomar laxantes?', 'De forma puntual no, pero no deben ser la solución habitual sin consejo médico. Primero, fibra, agua, movimiento y hábitos.'),
    ], 'faq-estr'), DIG)

art('hinchazon-abdominal', 'Hinchazón abdominal y gases: causas y qué comer | Nutriendo Hábitos',
    'Por qué se hincha la tripa después de comer, qué alimentos y hábitos dan gases y cuándo puede ser intestino irritable, SIBO o una intolerancia.',
    'tips', 'Hinchazón abdominal', 'Salud digestiva', 'Hinchazón abdominal y gases: causas y qué comer',
    'La tripa hinchada es una de las consultas más frecuentes. Te contamos las causas habituales y qué puedes hacer.',
    banda('Por qué se hincha la tripa', '<p>La hinchazón puede deberse a <strong>gas</strong> (por fermentación de algunos alimentos o por tragar aire), a <strong>estreñimiento</strong> o a una mayor <strong>sensibilidad del intestino</strong>, como ocurre en el intestino irritable, en la que una cantidad normal de gas se nota mucho.</p>')
    + stmts([
    ('utensils', 'Hábitos que la reducen', '<ul><li>Comer <strong>despacio</strong>, masticar bien y no hablar mientras comes.</li><li>Evitar bebidas con gas, chicles y pajitas.</li><li>Raciones moderadas, sin cenas copiosas.</li><li>Moverte después de comer: un paseo corto ayuda.</li></ul>'),
    ('salad', 'Alimentos que más fermentan', '<p>Legumbres, cebolla, ajo, coles, alcachofa, trigo en grandes cantidades, algunas frutas (manzana, pera, sandía), la leche si eres intolerante a la lactosa y los edulcorantes terminados en «-ol» (sorbitol, xilitol). No hay que quitarlos todos: observa cuáles te afectan y ajusta cantidades.</p>'),
    ('stetho', 'Cuando es algo más', '<p>Si la hinchazón es <strong>diaria</strong>, se acompaña de dolor, diarrea o estreñimiento, conviene descartar <strong>intestino irritable, SIBO, intolerancias o celiaquía</strong>. Una dieta baja en FODMAP bien guiada puede ayudar mucho. <a href="#dieta-fodmap">Guía FODMAP</a> · <a href="#dieta-sibo">Guía SIBO</a> · <a href="#patologias-digestivas-valladolid">Consulta de patologías digestivas</a>.</p><p>Acude al médico si pierdes peso, hay sangre en las heces, anemia, fiebre o vómitos.</p>'),
    ]) + faq([
    ('¿Qué comer para deshinchar la tripa?', 'Comidas sencillas: arroz, patata, huevo, pescado, pollo, verduras cocidas como calabacín o zanahoria, fruta como kiwi o naranja, y beber agua. Evita por unos días las bebidas con gas, las coles y las legumbres enteras.'),
    ('¿Los probióticos quitan la hinchazón?', 'Algunas cepas pueden ayudar en el intestino irritable, pero el efecto varía mucho. Conviene elegirlos con criterio profesional.'),
    ], 'faq-hin'), DIG)

art('dieta-hernia-de-hiato-reflujo', 'Dieta para la hernia de hiato y el reflujo: qué comer | Nutriendo Hábitos',
    'Qué comer y qué hábitos ayudan con la hernia de hiato y el reflujo gastroesofágico: comidas, horarios, postura al dormir y alimentos que pueden empeorarlo.',
    'tips', 'Hernia de hiato y reflujo', 'Salud digestiva', 'Dieta para la hernia de hiato y el reflujo',
    'Los hábitos cuentan tanto como los alimentos. Te explicamos qué cambios reducen el ardor y la acidez.',
    banda('Qué es la hernia de hiato', '<p>Ocurre cuando una parte del estómago sube a través del diafragma hacia el tórax. Muchas personas no notan nada, pero en otras favorece el <strong>reflujo</strong>: ardor, acidez, regurgitaciones, tos o carraspeo, sobre todo al tumbarse o después de comer mucho.</p>')
    + stmts([
    ('cal', 'Hábitos que más ayudan', '<ul><li>Comidas <strong>pequeñas</strong> y sin prisa; evita llenar mucho el estómago.</li><li><strong>No tumbarte en las 2-3 horas</strong> después de comer.</li><li><strong>Elevar el cabecero</strong> de la cama 15-20 cm o dormir sobre el lado izquierdo.</li><li>Evitar ropa y cinturones apretados.</li><li>Si tienes sobrepeso, perder algo de peso reduce mucho los síntomas.</li><li>Dejar de fumar.</li></ul>'),
    ('heart', 'Alimentos que pueden empeorarlo', '<p>Varía de una persona a otra. Los más habituales: comidas muy grasas y fritos, chocolate, menta, café, alcohol, bebidas con gas, picante, cítricos y tomate. No los quites todos a la vez: prueba uno a uno y observa.</p>'),
    ('apple', 'Qué suele sentar bien', '<p>Verduras cocidas, arroz, pasta, patata, pan, carnes magras y pescados a la plancha o al horno, huevo, legumbres si las toleras, yogur, frutas no ácidas como plátano, manzana o melón, y aceite de oliva en cantidad moderada.</p>'),
    ]) + faq([
    ('¿Qué cenar si tengo reflujo?', 'Algo ligero y pronto: crema de verduras y tortilla francesa, pescado al horno con patata, o arroz con pollo y verduras. Y espera 2-3 horas antes de acostarte.'),
    ('¿La hernia de hiato se cura con dieta?', 'La dieta y los hábitos controlan los síntomas en muchas personas; la hernia en sí no desaparece. Si los síntomas persisten, tu médico valorará tratamiento.'),
    ], 'faq-hh'), DIG)

# ------------------------------------------------------------ dietas
MENU = [('Lunes', 'Lentejas estofadas con verduras · Fruta', 'Crema de calabacín · Tortilla francesa con ensalada de tomate'),
        ('Martes', 'Arroz con pollo y verduras · Yogur', 'Merluza al horno con patata y pimiento · Fruta'),
        ('Miércoles', 'Ensalada de garbanzos con atún, tomate y cebolla · Fruta', 'Salteado de verduras con tofu o huevo · Pan integral'),
        ('Jueves', 'Pasta integral con boloñesa de carne picada y verduras · Fruta', 'Sopa de verduras · Sardinas a la plancha con ensalada'),
        ('Viernes', 'Alubias con verduras · Fruta', 'Pollo al horno con calabaza asada · Yogur'),
        ('Sábado', 'Paella de marisco o de verduras · Ensalada · Fruta', 'Pizza casera integral con verduras y mozzarella'),
        ('Domingo', 'Salmón al horno con patatas y brócoli · Fruta', 'Gazpacho o crema de verduras · Revuelto de setas con pan')]
menu = '<div class="nt-wrap"><table class="tl-tab"><thead><tr><th>Día</th><th>Comida</th><th>Cena</th></tr></thead><tbody>' + ''.join(f'<tr><td><b>{d}</b></td><td>{c}</td><td>{n}</td></tr>' for d, c, n in MENU) + '</tbody></table></div>'
art('dieta-mediterranea-menu-semanal', 'Dieta mediterránea: qué es, alimentos y menú semanal | Nutriendo Hábitos',
    'Qué es la dieta mediterránea, qué alimentos incluye y en qué cantidades, sus beneficios demostrados y un menú semanal de ejemplo para empezar.',
    'hero', 'Dieta mediterránea', 'Alimentación saludable', 'Dieta mediterránea: qué es y menú semanal de ejemplo',
    'El patrón de alimentación con más evidencia científica, explicado de forma práctica y con un menú de una semana.',
    banda('Qué es la dieta mediterránea', '<p>No es una dieta para adelgazar, sino un <strong>patrón de alimentación</strong> basado en verduras, frutas, legumbres, cereales integrales, frutos secos, pescado y <strong>aceite de oliva virgen extra</strong> como grasa principal, con poca carne roja, pocos ultraprocesados y comidas en compañía.</p><p>Estudios como <strong>PREDIMED</strong>, hecho en España, demostraron que reduce el riesgo de infarto e ictus. También se asocia a menos diabetes, algunos cánceres y deterioro cognitivo.</p>')
    + stmts([
    ('salad', 'Cada día', '<ul><li>Verduras en comida y cena, y 2-3 piezas de fruta.</li><li>Cereales, mejor integrales: pan, arroz, pasta, avena.</li><li>Aceite de oliva virgen extra para cocinar y aliñar.</li><li>Un puñado de frutos secos.</li><li>Agua como bebida habitual.</li></ul>'),
    ('cal', 'Cada semana', '<ul><li><strong>Legumbres</strong>: 3-4 veces.</li><li><strong>Pescado</strong>: 3-4 veces, alternando blanco y azul.</li><li><strong>Huevos</strong>: 3-5.</li><li>Carne blanca 2-3 veces; carne roja y procesada, poco.</li></ul>'),
    ]) + banda('Menú semanal de ejemplo', '<p>Para adultos sanos; adapta cantidades a tu hambre y actividad. Desayunos y meriendas: fruta, yogur o leche, pan integral con aceite y tomate, frutos secos.</p>' + menu)
    + faq([
    ('¿Se adelgaza con la dieta mediterránea?', 'Puede ayudar a perder peso si se ajustan las cantidades, y sobre todo a mantenerlo a largo plazo, porque es fácil de seguir y saciante.'),
    ('¿Se puede tomar vino en la dieta mediterránea?', 'Tradicionalmente aparece, pero hoy se sabe que ninguna cantidad de alcohol es beneficiosa para la salud. Si no bebes, no empieces.'),
    ], 'faq-med'), 'recetas,temporada,servicios')

art('ayuno-intermitente', 'Ayuno intermitente: qué es, tipos, beneficios y riesgos | Nutriendo Hábitos',
    'Qué es el ayuno intermitente (16:8, 5:2…), qué dice la ciencia sobre la pérdida de peso, sus posibles beneficios y quién no debería hacerlo.',
    'hero', 'Ayuno intermitente', 'Dietas a examen', 'Ayuno intermitente: qué es, qué dice la ciencia y para quién no es',
    'Una de las estrategias más de moda. Te explicamos qué funciona, qué no y en qué casos conviene evitarlo.',
    banda('Qué es el ayuno intermitente', '<p>Consiste en alternar periodos en los que se come con periodos sin comer. Los formatos más habituales son el <strong>16:8</strong> (comer en una ventana de 8 horas y ayunar 16, por ejemplo saltando el desayuno o la cena), el <strong>14:10</strong> y el <strong>5:2</strong> (dos días a la semana con muy pocas calorías).</p>')
    + stmts([
    ('scale', 'Qué dice la ciencia', '<p>Los estudios muestran que hace perder peso <strong>de forma parecida</strong> a una dieta con las mismas calorías repartidas de forma normal: funciona porque, al comer en menos tiempo, muchas personas comen menos. No hay pruebas sólidas de que «queme más grasa» por sí mismo. Puede mejorar algo el control de la glucosa en algunas personas.</p>'),
    ('heart', 'Puede encajarte si…', '<p>…te resulta cómodo no desayunar o cenar temprano, no te da ansiedad y te ayuda a ordenar tus comidas. Lo importante sigue siendo <strong>qué comes</strong> en la ventana de alimentación.</p>'),
    ('stetho', 'Mejor evitarlo si…', '<ul><li>Tienes o has tenido un <strong>trastorno de la conducta alimentaria</strong>.</li><li>Tienes diabetes con insulina o fármacos que bajan la glucosa (riesgo de hipoglucemias) sin supervisión.</li><li>Estás embarazada, en lactancia o eres menor de edad.</li><li>Entrenas mucho o tienes bajo peso.</li></ul>'),
    ]) + faq([
    ('¿Se puede tomar café durante el ayuno?', 'Sí, solo, sin azúcar ni leche; también agua e infusiones.'),
    ('¿El ayuno intermitente quema músculo?', 'Si la proteína y el ejercicio de fuerza son suficientes, la pérdida de músculo es similar a la de otras dietas. Sin ellos, puede perderse.'),
    ], 'faq-ayuno'), 'kcal,servicios,blog')

art('dieta-cetogenica', 'Dieta cetogénica (keto): qué es, riesgos y para quién | Nutriendo Hábitos',
    'Qué es la dieta cetogénica o keto, cómo funciona, sus usos médicos, si sirve para adelgazar, efectos secundarios y para quién no está indicada.',
    'hero', 'Dieta cetogénica', 'Dietas a examen', 'Dieta cetogénica (keto): qué es, para qué sirve y sus riesgos',
    'Una dieta muy baja en hidratos con usos médicos concretos y muchas promesas. Te contamos qué hay de cierto.',
    banda('Qué es la dieta cetogénica', '<p>Reduce los hidratos de carbono a menos de 20-50 g al día (ni pan, ni pasta, ni arroz, ni legumbres, ni casi fruta) y aumenta mucho la grasa. Así el cuerpo empieza a usar <strong>cuerpos cetónicos</strong> como combustible, en un estado llamado cetosis.</p>')
    + stmts([
    ('stetho', 'Usos médicos', '<p>Tiene un papel bien establecido en algunas <strong>epilepsias resistentes</strong> a fármacos, siempre con seguimiento médico. Se investiga en otras enfermedades, pero sin conclusiones firmes.</p>'),
    ('scale', '¿Sirve para adelgazar?', '<p>A corto plazo se pierde peso rápido, en parte agua. A medio y largo plazo los resultados son <strong>parecidos a otras dietas</strong>, y muchas personas la abandonan porque es difícil de mantener.</p>'),
    ('heart', 'Efectos y precauciones', '<ul><li>Los primeros días son frecuentes el cansancio, el dolor de cabeza y el estreñimiento («gripe keto»).</li><li>Puede subir el colesterol LDL en algunas personas.</li><li>Elimina alimentos muy saludables: legumbres, fruta, cereales integrales.</li><li>No es adecuada en el embarazo, con TCA, enfermedad renal o hepática, ni en diabetes tipo 1 o con ciertos fármacos sin supervisión.</li></ul>'),
    ]) + faq([
    ('¿Qué se come en la dieta keto?', 'Carne, pescado, huevos, quesos, aceite, aguacate, frutos secos, semillas y verduras bajas en hidratos. Se eliminan pan, pasta, arroz, patata, legumbres, azúcar y casi toda la fruta.'),
    ('¿Es mejor la keto o la mediterránea?', 'Para la salud a largo plazo, la mediterránea tiene mucha más evidencia y es más fácil de mantener. La keto puede tener sentido en casos concretos y supervisados.'),
    ], 'faq-keto'), 'servicios,kcal,blog')

art('dieta-antiinflamatoria', 'Dieta antiinflamatoria: alimentos y menú, qué hay de cierto | Nutriendo Hábitos',
    'Qué es la dieta antiinflamatoria, qué alimentos incluye y cuáles limitar, qué dice la evidencia y cómo aplicarla sin caer en mitos.',
    'hero', 'Dieta antiinflamatoria', 'Dietas a examen', 'Dieta antiinflamatoria: qué es y qué hay de cierto',
    'Una forma de comer muy parecida a la mediterránea que ayuda a reducir la inflamación crónica de bajo grado.',
    banda('Inflamación crónica y alimentación', '<p>La inflamación de bajo grado, que se mantiene en el tiempo, se relaciona con enfermedades cardiovasculares, diabetes tipo 2, obesidad o algunas enfermedades autoinmunes. La alimentación es <strong>uno de los factores</strong> que influyen, junto con el sueño, el estrés, el tabaco, el ejercicio y el peso.</p><p>No hay un alimento «antiinflamatorio» milagroso: lo que funciona es el <strong>patrón completo</strong>, muy parecido a la dieta mediterránea.</p>')
    + stmts([
    ('salad', 'Más de esto', '<ul><li>Verduras y frutas de colores variados.</li><li>Legumbres y cereales integrales.</li><li>Pescado azul y frutos secos (omega 3).</li><li>Aceite de oliva virgen extra.</li><li>Especias y hierbas: cúrcuma, jengibre, orégano, romero.</li><li>Yogur y alimentos fermentados.</li></ul>'),
    ('heart', 'Menos de esto', '<ul><li>Ultraprocesados, bollería y snacks.</li><li>Azúcar y bebidas azucaradas.</li><li>Carnes procesadas y exceso de carne roja.</li><li>Harinas refinadas.</li><li>Alcohol.</li></ul>'),
    ('stetho', 'Sin caer en mitos', '<p>No hace falta eliminar el gluten, los lácteos o las solanáceas (tomate, pimiento, berenjena) salvo que tengas una enfermedad o intolerancia diagnosticada. Las dietas muy restrictivas pueden hacer más daño que bien.</p>'),
    ]) + faq([
    ('¿La cúrcuma es antiinflamatoria?', 'En laboratorio sí, pero en las cantidades de la cocina su efecto es pequeño. Úsala como parte de una alimentación saludable, no como remedio.'),
    ('¿Sirve la dieta antiinflamatoria para la artrosis o la artritis?', 'Puede ayudar como complemento del tratamiento, sobre todo si favorece perder peso. No sustituye al tratamiento médico.'),
    ], 'faq-antiinf'), 'recetas,servicios,nutrientes')

# ------------------------------------------------------------ ideas de comidas
art('cenas-saludables-rapidas', 'Cenas saludables y rápidas: 15 ideas fáciles | Nutriendo Hábitos',
    '15 ideas de cenas saludables, rápidas y ligeras para entre semana, con el método del plato para que queden equilibradas y saciantes.',
    'tips', 'Cenas saludables', 'Ideas para comer mejor', 'Cenas saludables y rápidas: 15 ideas fáciles',
    'Cenar bien no tiene por qué ser complicado. Con el método del plato y estas ideas, en 15-20 minutos.',
    banda('El método del plato', '<p>Para que la cena sea equilibrada y te sacie: <strong>la mitad del plato de verduras</strong>, un cuarto de <strong>proteína</strong> (huevo, pescado, legumbres, pollo, tofu) y un cuarto de <strong>hidratos</strong> (pan integral, patata, arroz, quinoa), con aceite de oliva. Si cenas tarde o con poca hambre, reduce los hidratos.</p>')
    + banda('15 ideas', '<ol><li>Tortilla francesa con ensalada de tomate y pan integral.</li><li>Merluza al horno con patata y pimiento.</li><li>Crema de calabacín con huevo cocido.</li><li>Salteado de verduras con gambas.</li><li>Ensalada templada de lentejas con verduras asadas.</li><li>Revuelto de setas y espárragos con tostada.</li><li>Sardinas a la plancha con pimientos asados.</li><li>Wrap integral de pollo, lechuga, tomate y yogur.</li><li>Tofu salteado con brócoli y arroz.</li><li>Sopa de verduras con fideos y huevo.</li><li>Salmón al microondas con judías verdes.</li><li>Pizza casera integral de verduras.</li><li>Hamburguesa de garbanzos con ensalada.</li><li>Huevos al plato con tomate y guisantes.</li><li>Ensalada de quinoa con atún, maíz y aguacate.</li></ol><p>¿Te falta inspiración? Usa <a href="#recetas-con-lo-que-tengo">¿Qué cocino con lo que tengo?</a> con lo que tengas en la nevera.</p>')
    + faq([
    ('¿Es malo cenar hidratos?', 'No. Lo importante es la cantidad total del día y la calidad. Una ración moderada de pan integral, patata o arroz en la cena puede incluso ayudar a dormir mejor.'),
    ('¿A qué hora es mejor cenar?', 'Idealmente 2-3 horas antes de acostarte, para dormir mejor y evitar el reflujo.'),
    ], 'faq-cenas'), 'recetas,temporada,servicios')

art('desayunos-saludables', 'Desayunos saludables: 15 ideas rápidas y saciantes | Nutriendo Hábitos',
    '15 ideas de desayunos saludables, dulces y salados, rápidos y saciantes. Qué incluir en un buen desayuno y mitos sobre la comida más importante del día.',
    'tips', 'Desayunos saludables', 'Ideas para comer mejor', 'Desayunos saludables: 15 ideas rápidas y saciantes',
    'Ideas dulces y saladas para empezar el día sin bollería ni azúcar, en pocos minutos.',
    banda('¿Hay que desayunar sí o sí?', '<p>No es obligatorio: si no tienes hambre por la mañana y comes bien el resto del día, no pasa nada. Pero si desayunas, que sume. Un buen desayuno combina <strong>proteína</strong> (lácteo, huevo, queso fresco, jamón), <strong>cereal integral</strong> (pan, avena) y <strong>fruta</strong>, con algo de grasa saludable (aceite, frutos secos, aguacate).</p>')
    + banda('15 ideas', '<ol><li>Tostada integral con tomate, aceite y jamón.</li><li>Yogur natural con fruta troceada y nueces.</li><li>Porridge de avena con leche, plátano y canela.</li><li>Tostada con aguacate y huevo.</li><li>Queso fresco batido con fresas y semillas.</li><li>Tortilla francesa con pan integral.</li><li>Tostada de hummus con tomate.</li><li>Batido de leche o bebida de soja con plátano y avena.</li><li>Pan integral con queso fresco y pavo.</li><li>Tortitas de avena y plátano.</li><li>Yogur griego natural con kiwi y copos de avena.</li><li>Tostada con crema de cacahuete 100 % y plátano.</li><li>Revuelto de huevo con espinacas.</li><li>Bocadillo pequeño de atún y tomate.</li><li>Fruta, un puñado de frutos secos y un café con leche, para los días de prisa.</li></ol>')
    + faq([
    ('¿Los cereales de desayuno son saludables?', 'La mayoría llevan mucho azúcar. Mejor copos de avena o muesli sin azúcar añadido. Compruébalo con nuestro <a href="#lector-de-etiquetas">lector de etiquetas</a>.'),
    ('¿El zumo de naranja es bueno para desayunar?', 'Mejor la naranja entera: el zumo pierde la fibra y sacia menos.'),
    ], 'faq-des'), 'recetas,etiquetas,servicios')

art('almuerzos-meriendas-saludables-ninos', 'Almuerzos y meriendas saludables para niños: ideas para el cole | Nutriendo Hábitos',
    'Ideas de almuerzos para el recreo y meriendas saludables para niños, fáciles y sin bollería, con consejos sobre bebidas, cantidades y alergias.',
    'hero', 'Almuerzos y meriendas para niños', 'Nutrición infantil', 'Almuerzos y meriendas saludables para niños',
    'Ideas para el recreo y la merienda que gustan a los peques y no llevan bollería ni zumos.',
    banda('La idea general', '<p>El almuerzo y la merienda no tienen que ser grandes: son para llegar con energía a la comida o la cena. La base: <strong>fruta</strong>, <strong>bocadillo de pan (mejor integral)</strong>, <strong>lácteos</strong> o <strong>frutos secos</strong> (enteros solo a partir de los 6 años por riesgo de atragantamiento; antes, en crema o molidos). Para beber, <strong>agua</strong>.</p>')
    + stmts([
    ('school', 'Para el recreo', '<ul><li>Bocadillo pequeño de tortilla, de queso, de pavo o de hummus.</li><li>Fruta fácil de comer: mandarina, plátano, uvas cortadas, manzana en gajos.</li><li>Palitos de zanahoria o pepino con un poco de queso.</li><li>Tortitas de maíz o arroz con crema de cacahuete 100 %.</li><li>Un puñado de frutos secos (mayores de 6 años, si el cole lo permite).</li></ul>'),
    ('home', 'Para merendar en casa', '<ul><li>Yogur natural con fruta.</li><li>Tostada con aceite y tomate.</li><li>Batido casero de leche y fruta.</li><li>Bizcocho casero con poco azúcar, de vez en cuando.</li><li>Brochetas de fruta.</li></ul>'),
    ('heart', 'Mejor evitar a diario', '<p>Bollería, galletas, zumos y batidos industriales, embutidos muy grasos y snacks salados. No hace falta prohibirlos, pero sí que sean ocasionales. Y nunca usar la comida como premio o castigo.</p>'),
    ]) + faq([
    ('¿Los zumos cuentan como fruta?', 'No conviene: aportan azúcar libre y poca fibra. Mejor la fruta entera, y agua para beber.'),
    ('¿Qué hago si mi hijo come muy poco?', 'Ofrece sin presionar, respeta su apetito y cuida el ambiente en la mesa. Si te preocupa su crecimiento, consulta. <a href="#nutricion-infantil-valladolid">Nutrición infantil</a>.'),
    ], 'faq-ninos'), 'infantil,recetas,servicios')

art('dieta-vegana-nutrientes', 'Dieta vegana y vegetariana: nutrientes clave y cómo cubrirlos | Nutriendo Hábitos',
    'Cómo cubrir proteína, hierro, vitamina B12, calcio, omega 3, yodo y vitamina D en una dieta vegana o vegetariana. Guía práctica de una dietista-nutricionista.',
    'hero', 'Nutrientes en la dieta vegana', 'Alimentación vegetariana', 'Dieta vegana y vegetariana: nutrientes clave y cómo cubrirlos',
    'Una dieta vegetariana bien planificada es saludable en todas las etapas de la vida. Estos son los nutrientes que hay que vigilar.',
    banda('Bien planificada, es saludable', '<p>Las principales sociedades de dietética, como la Academy of Nutrition and Dietetics, consideran que una alimentación vegetariana o vegana <strong>bien planificada</strong> es adecuada en todas las etapas de la vida. La clave está en planificarla, no en quitar la carne sin más.</p>')
    + stmts([
    ('stetho', 'Vitamina B12: suplemento siempre', '<p>Es el único nutriente que <strong>no se puede cubrir con alimentos vegetales</strong>. Las personas veganas necesitan un suplemento de B12 de forma constante. <a href="#alimentos-ricos-en-vitamina-b12">Más sobre la B12</a>.</p>'),
    ('activity', 'Proteína', '<p>Legumbres a diario, tofu, tempeh, soja texturizada, seitán, frutos secos y semillas. Combinadas con cereales a lo largo del día cubren todos los aminoácidos. <a href="#calculadora-proteinas">Calcula tu proteína</a>.</p>'),
    ('heart', 'Hierro', '<p>Legumbres, tofu, frutos secos, semillas, cereales integrales y verduras de hoja, siempre con <strong>vitamina C</strong> en la misma comida. <a href="#alimentos-ricos-en-hierro">Alimentos ricos en hierro</a>.</p>'),
    ('leaf', 'Calcio, omega 3, yodo y vitamina D', '<ul><li><strong>Calcio</strong>: tofu con calcio, bebidas vegetales enriquecidas, almendras, sésamo, brócoli.</li><li><strong>Omega 3</strong>: lino molido, chía, nueces; valorar aceite de algas.</li><li><strong>Yodo</strong>: usa sal yodada.</li><li><strong>Vitamina D</strong>: sol y, si hace falta, suplemento según analítica.</li></ul>'),
    ]) + faq([
    ('¿Los veganos tienen más riesgo de anemia?', 'No necesariamente si la dieta está bien planificada: suelen tomar mucho hierro vegetal y vitamina C. Sí conviene vigilar la ferritina, sobre todo en mujeres con reglas abundantes.'),
    ('¿Puede un niño ser vegano?', 'Sí, con una planificación cuidadosa, suplemento de B12 y seguimiento profesional.'),
    ], 'faq-veg'), 'vegetariana,nutrientes,prot')

# ------------------------------------------------------------ guía de quimioterapia (retirada de la web el 8-11-2026)
_cancer = (AQUI.parent / 'borrador-guia-cancer.html').read_text(encoding='utf-8')
_seg = _cancer[_cancer.index('<div data-page="alimentacion-cancer-quimioterapia"'):]
_seg = _seg.replace('<div data-page="alimentacion-cancer-quimioterapia" hidden>',
    '<div data-page="alimentacion-cancer-quimioterapia" data-titulo="Alimentación durante la quimioterapia y el tratamiento del cáncer | Nutriendo Hábitos" data-desc="Consejos prácticos para comer bien durante la quimioterapia: falta de apetito, náuseas, cambios de sabor, boca seca y mitos sobre la alimentación y el cáncer." hidden>', 1)
ARTS.append(('alimentacion-cancer-quimioterapia', '', '', _seg.rstrip() + '\n'))

# Orden del índice: primero los que pidió Jairo (25-11-2026), después los extra
PEDIDOS = ['dieta-gastritis', 'dieta-estrenimiento', 'hinchazon-abdominal', 'dieta-hernia-de-hiato-reflujo',
           'dieta-mediterranea-menu-semanal', 'ayuno-intermitente', 'dieta-cetogenica', 'dieta-antiinflamatoria',
           'cenas-saludables-rapidas', 'desayunos-saludables', 'almuerzos-meriendas-saludables-ninos',
           'dieta-vegana-nutrientes', 'alimentacion-cancer-quimioterapia']

if __name__ == '__main__':
    pos = {s_: i for i, s_ in enumerate(PEDIDOS)}
    orden = sorted(ARTS, key=lambda a: pos.get(a[0], 100 + [x[0] for x in ARTS].index(a[0])))
    segs = [a[3].replace(f'<div data-page="{a[0]}" ', f'<div data-page="{a[0]}" data-grupo="{"pedido" if a[0] in pos else "extra"}" ', 1) for a in orden]
    out = '<!-- GENERADO por borradores/articulos.py: no editar a mano -->\n' + '\n'.join(segs)
    (AQUI.parent / 'borradores.html').write_text(out, encoding='utf-8')
    print(len(ARTS), 'borradores')
