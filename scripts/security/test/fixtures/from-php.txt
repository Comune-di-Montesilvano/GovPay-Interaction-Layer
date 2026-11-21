# Copia fissa (2026-11-21) delle righe FROM di Dockerfile per i test di autofix: mai il file vivo.
FROM node:lts-trixie-slim@sha256:e6bedef217677b276c591795e169840edd9096dbf13f29df8ef8d1856b8820bc AS asset_builder
FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS vendor_builder
FROM php:8.5-apache-trixie@sha256:7cd25eb48f9763dfeabe8fda3939897aca2001802fadeafa1b602f26c735ef96 AS runtime-base
FROM runtime-base AS runtime-backoffice
FROM runtime-base AS runtime-frontoffice
