.PHONY: up down logs reset test
up:      ## Costruisce e avvia l'app su http://localhost:8080
	docker compose up -d --build
	@echo "→ http://localhost:$${APP_PORT:-8080}"
down:    ## Ferma l'app (mantiene i dati)
	docker compose down
logs:
	docker compose logs -f
reset:   ## Azzera tutto (database incluso) e riparte con i dati demo freschi
	docker compose down -v && docker compose up -d --build
test:    ## Esegue i test nel container, su un database temporaneo
	docker compose up -d db
	docker compose run --rm --no-deps -e NODE_ENV=test -e DATABASE_URL=postgres://verdesalva:$${DB_PASSWORD:-verdesalva}@db:5432/postgres verdesalva node --test "test/*.test.js"
