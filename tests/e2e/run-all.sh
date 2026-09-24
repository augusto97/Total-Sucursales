#!/usr/bin/env bash
# Corre toda la batería en el orden correcto y deja el sitio como lo espera e2e.js.
#
#   bash run-all.sh [directorio del entorno]     # por defecto, el directorio de este script
#
# Las pruebas clásicas necesitan el tema twentytwentyone y las páginas con shortcode; e2e-blocks.js,
# twentytwentyfive y las páginas de bloques. Se repone el stock antes de cada prueba porque todas crean
# pedidos reales. Los logs quedan en <entorno>/logs/.
set -u
DIR="${1:-$(cd "$(dirname "$0")" && pwd)}"
cd "$DIR" || exit 1
W="php wp-cli.phar --allow-root --path=wordpress"
mkdir -p logs

up() {
	curl -s -o /dev/null http://127.0.0.1:8080/ && return
	nohup php -S 127.0.0.1:8080 -t wordpress router.php >/dev/null 2>&1 &
	sleep 2
}
mode() { # classic | blocks
	if [ "$1" = blocks ]; then $W theme activate twentytwentyfive >/dev/null 2>&1; else $W theme activate twentytwentyone >/dev/null 2>&1; fi
	$W eval-file pages.php "$1" >/dev/null 2>&1
}
total_fail=0
run() {
	up
	$W eval-file reset-stock.php >/dev/null 2>&1
	timeout 600 node "$1.js" > "logs/$1.log" 2>&1
	local code=$? fails
	fails=$(grep -c '^FAIL' "logs/$1.log")
	echo "$1: exit=$code, $fails FAIL · $(tail -1 "logs/$1.log")"
	[ "$code" -eq 0 ] && [ "$fails" -eq 0 ] || total_fail=$((total_fail + 1))
}

echo -n "regla-retiro: "; $W eval-file regla-retiro.php 2>/dev/null | tee logs/regla-retiro.log | tail -1
grep -q '^FAIL' logs/regla-retiro.log && total_fail=$((total_fail + 1))

mode classic
for t in e2e retiro-municipio ficha-municipio envio-sin-was textos-tienda sucursal-por-defecto repro-backorder repro-switcher-loop; do
	run "$t"
done
mode blocks
run e2e-blocks
mode classic

echo
[ "$total_fail" -eq 0 ] && echo "Batería completa: todo OK" || echo "Batería completa: $total_fail pruebas con fallos (ver logs/)"
exit "$total_fail"
