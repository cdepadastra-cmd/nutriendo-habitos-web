# Genera nutrientes.json para la tabla «Alimentos ricos en proteínas, vitaminas y omega 3».
# Toma los alimentos de alimentos.json (nombres en español, ración) y sus vitaminas, minerales y
# omega 3 de usda_sr.json (USDA FoodData Central SR Legacy, dominio público), unidos por el id FDC.
# None = la base no tiene ese dato para ese alimento.
import json, os, sys
B = os.path.dirname(os.path.abspath(__file__))
A = json.load(open(os.path.join(B, 'alimentos.json'), encoding='utf-8'))
U = {x['id']: x for x in json.load(open(os.path.join(B, 'usda_sr.json'), encoding='utf-8'))}
POR_DESC = {x['d']: x for x in U.values()}
SIN_ENRIQ = {'Rice, white, long-grain, regular, raw, enriched': 'Rice, white, long-grain, regular, raw, unenriched',
             'Rice, white, long-grain, regular, cooked, enriched, with salt': 'Rice, white, long-grain, regular, cooked, unenriched, with salt',
             'Pasta, dry, enriched': 'Pasta, dry, unenriched', 'Pasta, cooked, enriched, without added salt': 'Pasta, cooked, unenriched, without added salt',
             'Wheat flour, white, all-purpose, enriched, bleached': 'Wheat flour, white, all-purpose, unenriched'}
SIN_DATO_ENRIQ = {'Pan blanco'}
FUERA = {'Bebidas', 'Dulces y otros'}
# Mayonesa y margarina: en EE. UU. se hacen con aceite de soja/colza y su omega 3 no representa a las españolas.
QUITAR = {'Mayonesa', 'Margarina'}
NOMBRE = {'Cereales de desayuno tipo corn flakes': 'Cereales de desayuno enriquecidos (tipo corn flakes)', 'Bebida de almendra sin azúcar': 'Bebida de almendra sin azúcar (enriquecida en calcio)'}
def r(v, d=1):
    return None if v is None else round(v, d)
filas = []
for a in A['a']:
    if a[1] in FUERA or a[0] in QUITAR: continue
    u = U.get(a[12])
    if not u: continue
    # En EE. UU. el arroz, la pasta, la harina y el pan se enriquecen por ley con hierro y vitaminas B; en España no.
    # Para vitaminas y minerales usamos la versión sin enriquecer de la USDA, o lo dejamos sin dato.
    sin = SIN_ENRIQ.get(u['d'])
    if sin and sin in POR_DESC:
        v = POR_DESC[sin]; u = dict(u, **{k: v.get(k) for k in ('hierro', 'b6', 'b12', 'folato', 'folato_dfe', 'calcio', 'vitc', 'vitd')})
    elif a[0] in SIN_DATO_ENRIQ:
        u = dict(u, hierro=None, folato=None, folato_dfe=None, calcio=None)
    ala = u.get('ala', u.get('ala_tot'))
    partes = [ala, u.get('epa'), u.get('dha'), u.get('dpa')]
    om3 = None if all(p is None for p in partes) else sum(p or 0 for p in partes)
    lc = [u.get('epa'), u.get('dha'), u.get('dpa')]
    epadha = None if all(p is None for p in lc) else 1000 * sum(p or 0 for p in lc)
    filas.append([NOMBRE.get(a[0], a[0]), a[1], a[2], r(u.get('prot')), r(u.get('b12'), 2), r(u.get('b6'), 2), r(u.get('vitc')), r(u.get('vitd')),
                  r(om3, 2), r(epadha, 0), r(u.get('hierro')), r(u.get('calcio'), 0), r(u.get('folato_dfe', u.get('folato')), 0),
                  r(u.get('fibra')), a[10], a[11]])
cats = [c for c in A['cats'] if c not in FUERA]
out = {'cats': cats, 'a': filas}
json.dump(out, open(sys.argv[1] if len(sys.argv) > 1 else os.path.join(B, 'nutrientes.json'), 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
print(len(filas), 'alimentos')
