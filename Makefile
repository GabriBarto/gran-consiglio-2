.PHONY: up down logs reset
up:      ## Costruisce e avvia l'app su http://localhost:8080
	docker compose up -d --build
	@echo "→ http://localhost:$${APP_PORT:-8080}"
down:    ## Ferma l'app (mantiene i dati)
	docker compose down
logs:
	docker compose logs -f
reset:   ## Azzera tutto e riparte con i dati demo freschi
	docker compose down -v && docker compose up -d --build
