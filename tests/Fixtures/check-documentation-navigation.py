"""Check the real built site in Chromium, directly from file://."""
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

with sync_playwright() as playwright:
    browser = playwright.chromium.launch()
    page = browser.new_page(viewport={"width": 1600, "height": 1000})
    page.goto((Path(sys.argv[1]) / "dev-doc/site/index.html").resolve().as_uri())
    sidebar = page.locator(".md-sidebar--primary")
    expect(sidebar).to_be_visible()
    chapter = sidebar.locator(".md-nav__item--nested").filter(has_text="Getting Started")
    link = chapter.get_by_role("link", name="Installer le Back", exact=True)
    expect(link).not_to_be_visible()
    chapter.locator("label.md-nav__link").first.click()
    expect(link).to_be_visible()
    link.click()
    expect(page.locator("h1")).to_have_text("Installer le Back")
    expect(sidebar.get_by_role("link", name="Setup local", exact=True)).to_be_visible()
    toc = page.locator(".md-sidebar--secondary")
    expect(toc).to_be_visible()
    expect(toc.get_by_text("Sommaire", exact=True)).to_be_visible()
    toc.get_by_role("link", name="Installation", exact=True).click()
    assert page.url.endswith("#installation"), page.url
    project = sidebar.locator(".md-nav__item--nested").filter(has_text="Documentation du projet")
    project.locator("label.md-nav__link").first.click()
    project.get_by_role("link", name="Mes notes", exact=True).click()
    expect(page.locator("h1")).to_have_text("Mes notes")
    browser.close()
