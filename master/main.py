"""
GovPay Interaction Layer — Master Container
API interna per gestione configurazione e container.
Porta 8099, accessibile SOLO dalla rete interna govpay-network.
"""
from fastapi import FastAPI
from routers import health, config, containers, backup, auth_proxy

app = FastAPI(
    title="GIL Master",
    description="API interna del Master Container per gestione config e container GIL",
    version="1.0.0",
    docs_url="/docs",   # accessibile solo internamente
    redoc_url=None,
)

app.include_router(health.router)
app.include_router(config.router)
app.include_router(containers.router)
app.include_router(backup.router)
app.include_router(auth_proxy.router)
