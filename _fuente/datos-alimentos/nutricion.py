# Valor nutricional aproximado por ración de cada receta.
# Usa la misma base que la herramienta de alimentos (USDA FoodData Central SR Legacy, dominio público):
# alimentos.json (nombres en español) y, para los pocos ingredientes que no están allí, usda_sr.json por id.
# Cada ingrediente: cantidad en texto -> gramos (pesos medios de pieza) -> nutrientes. No cuenta
# los ingredientes opcionales, ni la sal añadida, ni especias/agua (aporte despreciable).
import json, re, os

BASE = os.path.dirname(os.path.abspath(__file__))
def _abre(n):
    for d in (BASE, os.path.join(BASE, '..', 'datos-alimentos'), '/home/claude/nutriendo-habitos-web/_fuente/datos-alimentos'):
        p = os.path.join(d, n)
        if os.path.exists(p): return json.load(open(p, encoding='utf-8'))
    raise FileNotFoundError(n)

ALI = {a[0]: a for a in _abre('alimentos.json')['a']}
USDA = {x['id']: x for x in _abre('usda_sr.json')}
CAMPOS = ['kcal', 'prot', 'hc', 'azuc', 'fibra', 'grasa', 'sat', 'sodio']

def por100(clave):
    """Nutrientes por 100 g: nombre de alimentos.json o 'usda:<id>'."""
    if clave.startswith('usda:'):
        x = USDA[clave[5:]]
        return [x.get(k) or 0 for k in CAMPOS]
    a = ALI[clave]
    return [v or 0 for v in a[2:10]]

# ingrediente de la receta -> alimento de la base (puede depender del texto de la cantidad)
def alimento(n, q):
    q = q.lower()
    if n == 'pollo':
        if '200 g cocinado' in q: return 'Pechuga de pollo a la plancha'
        return 'Pechuga de pollo (crudo)' if ('pechuga' in q.split(' o ')[0]) else 'Muslo de pollo sin piel (crudo)'
    if n == 'arroz': return 'Arroz blanco cocido' if 'cocido' in q.split('(')[0] else 'Arroz blanco (crudo)'
    if n == 'lentejas': return 'Lentejas (crudas, en seco)' if 'seco' in q else 'Lentejas cocidas'
    M = {
        'aceite de oliva': 'Aceite de oliva', 'aceitunas': 'Aceitunas verdes', 'aguacate': 'Aguacate', 'ajo': 'Ajo',
        'alubias': 'Alubias blancas cocidas', 'atún en lata': 'Atún en lata al natural', 'avena': 'Copos de avena',
        'bacalao': 'Bacalao fresco (crudo)', 'berenjena': 'Berenjena', 'boniato': 'Boniato asado', 'brócoli': 'Brócoli',
        'cacao puro': 'Cacao en polvo puro', 'calabacín': 'Calabacín', 'calabaza': 'Calabaza',
        'caldo de pollo': 'usda:174536', 'caldo de verduras': 'usda:171583', 'carne picada': 'Carne picada de ternera 15 % grasa (cruda)',
        'cebolla': 'Cebolla', 'champiñones': 'Champiñones', 'chorizo': 'Chorizo', 'coliflor': 'Coliflor', 'cuscús': 'usda:169699',
        'espinacas': 'Espinacas', 'frutos rojos': 'Fresas', 'frutos secos': 'Nueces', 'gambas': 'Gambas / langostinos (crudos)',
        'garbanzos': 'Garbanzos cocidos', 'guisantes': 'Guisantes', 'harina': 'Harina de trigo', 'huevos': 'Huevo de gallina',
        'jamón': 'Jamón cocido', 'judías verdes': 'Judías verdes', 'leche': 'Leche semidesnatada', 'leche de coco': 'usda:170173',
        'lechuga': 'Lechuga', 'limón': 'Limón', 'lomo de cerdo': 'Lomo de cerdo (crudo)', 'manzana': 'Manzana', 'maíz': 'Maíz dulce',
        'mejillones': 'Mejillones (crudos)', 'merluza': 'Pescadilla (cruda)', 'mozzarella': 'Mozzarella', 'nata': 'usda:170857',
        'pan': 'Pan integral', 'pasta': 'Pasta (cruda)', 'patata': 'Patata (cruda)', 'pavo': 'Pavo, pechuga (crudo)',
        'pepino': 'Pepino', 'pimiento': 'Pimiento rojo', 'plátano': 'Plátano', 'puerro': 'Puerro', 'queso feta': 'usda:173420',
        'queso fresco': 'Requesón / ricotta', 'queso rallado': 'Queso curado (tipo parmesano)', 'quinoa': 'usda:168874',
        'salmón': 'Salmón (crudo)', 'salsa de soja': 'usda:174277', 'sardinas en lata': 'Sardinas en aceite (lata)',
        'semillas': 'Sésamo', 'tofu': 'Tofu firme', 'tomate': 'Tomate', 'tomate triturado': 'Tomate triturado (lata)',
        'tortillas de trigo': 'usda:173242', 'vinagre': 'usda:173469', 'vino blanco': 'usda:173195', 'yogur natural': 'Yogur natural',
        'zanahoria': 'Zanahoria',
    }
    return M.get(n)

# peso medio de 1 unidad (g) por ingrediente; "grande"/"pequeña" ajustan
PIEZA = {'huevos': 55, 'patata': 200, 'cebolla': 150, 'ajo': 5, 'tomate': 150, 'pimiento': 150, 'zanahoria': 80, 'calabacín': 250,
         'berenjena': 300, 'puerro': 100, 'limón': 60, 'plátano': 120, 'manzana': 150, 'aguacate': 140, 'brócoli': 400,
         'coliflor': 600, 'pepino': 300, 'mozzarella': 125, 'tortillas de trigo': 40, 'yogur natural': 125, 'boniato': 250,
         'pollo': 200, 'pavo': 120, 'lomo de cerdo': 100, 'merluza': 150, 'salmón': 125, 'bacalao': 150, 'atún en lata': 56,
         'sardinas en lata': 60, 'jamón': 15, 'pan': 30, 'lechuga': 300}
CUCHARADA = {'aceite de oliva': 10, 'queso rallado': 7, 'avena': 10, 'guisantes': 15, 'maíz': 15, 'yogur natural': 15,
             'salsa de soja': 15, 'vinagre': 15, 'semillas': 10, 'pan': 8, 'cacao puro': 6, 'harina': 8}
VAGO = {('espinacas', 'puñado'): 30, ('frutos secos', 'puñado'): 25, ('frutos secos', 'nueces'): 15, ('aceitunas', 'puñado'): 30,
        ('aceitunas', 'pocas'): 15, ('frutos rojos', 'puñado'): 80, ('frutos rojos', 'acompañar'): 80, ('cebolla', 'trozo'): 30,
        ('cebolla', 'poco'): 20, ('aceite de oliva', 'chorrito'): 5, ('leche', 'chorrito'): 30, ('lechuga', 'bolsa'): 100,
        ('lechuga', 'hojas'): 30, ('limón', 'zumo'): 20, ('pasta', 'puñado'): 30, ('semillas', 'decorar'): 5, ('semillas', 'gusto'): 5}

def num(t):
    t = t.replace(',', '.')
    m = re.match(r'\s*(\d+(?:\.\d+)?)\s*/\s*(\d+)', t)
    if m: return float(m.group(1)) / float(m.group(2))
    m = re.match(r'\s*(\d+(?:\.\d+)?)(?:\s*-\s*(\d+(?:\.\d+)?))?', t)
    if m: return (float(m.group(1)) + float(m.group(2) or m.group(1))) / 2
    return None

def gramos(n, q):
    """Gramos de parte comestible a partir del texto de la cantidad. None = no se puede estimar."""
    ql = q.lower()
    q0 = ql.split(' o ')[0] if not re.match(r'^\d+ (rodajas|contramuslos|muslos)', ql) else ql.split(' o ')[0]
    if n == 'mejillones': return 1000 * 0.25  # 1 kg con concha -> ~25 % comestible
    if n in ('garbanzos', 'lentejas', 'alubias') and 'bote' in q0:
        if '800 g' in q0: return 480
        return (num(q0) or 1) * 240  # bote de 400 g escurrido
    for (ing, pal), g in VAGO.items():
        if ing == n and pal in ql: return g
    m = re.search(r'(\d+(?:[.,]\d+)?)\s*(kg|g|ml|l)\b', q0)
    if m:
        v = float(m.group(1).replace(',', '.')); u = m.group(2)
        return v * (1000 if u in ('kg', 'l') else 1)
    if 'cucharadita' in q0: return (num(q0) or 1) * CUCHARADA.get(n, 6) / 3
    if 'cucharada' in q0: return (num(q0) or 1) * CUCHARADA.get(n, 10)
    k = num(q0)
    if k is None:
        if q0.startswith('un') and n in PIEZA: k = 1
        else: return None
    p = PIEZA.get(n)
    if p is None: return None
    if n == 'pollo' and ('contramuslo' in q0 or 'muslo' in q0): p = 120
    if n == 'pollo' and 'filete' in q0: p = 200
    if n == 'merluza' and 'rodaja' in q0: p = 80
    if n == 'limón' and 'zumo' in ql: p = 40
    if 'grande' in q0: p *= 1.4
    if 'pequeñ' in q0: p *= 0.6
    if n == 'ajo' and 'pequeño' in q0: p = 3
    if n == 'pan' and 'grande' in q0: p = 40
    return k * p

IGNORAR = {'sal', 'pimienta', 'agua', 'pimentón', 'comino', 'orégano', 'laurel', 'perejil', 'especias'}

def nutricion(receta):
    """Devuelve ([kcal, prot, hc, azuc, fibra, grasa, sat, sal] por ración, avisos)."""
    tot = [0.0] * 8; avisos = []
    for n, q in receta['ing']:
        if n in IGNORAR or 'opcional' in q.lower(): continue
        a = alimento(n, q); g = gramos(n, q)
        if a is None or g is None:
            avisos.append(f'{n}: {q}'); continue
        v = por100(a)
        for i in range(8): tot[i] += v[i] * g / 100
    r = receta['rac']
    p = [x / r for x in tot]
    out = [round(p[0]), round(p[1], 1), round(p[2], 1), round(p[3], 1), round(p[4], 1), round(p[5], 1), round(p[6], 1), round(p[7] * 2.5 / 1000, 2)]
    return out, avisos

if __name__ == '__main__':
    import sys; sys.path.insert(0, BASE)
    from recetas import R
    for r in R:
        v, av = nutricion(r)
        print(f"{v[0]:5d} kcal  P{v[1]:5.1f} HC{v[2]:5.1f} G{v[5]:5.1f} sal{v[7]:4.2f}  {r['t']} ({r['rac']})", ('  !! ' + '; '.join(av)) if av else '')
