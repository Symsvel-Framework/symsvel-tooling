"""Check the real built site in Chromium, directly from file://."""
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

with sync_playwright() as playwright:
    browser = playwright.chromium.launch()
    page = browser.new_page(viewport={"width": 1600, "height": 1000})
    page.goto((Path(sys.argv[1]) / "dev-doc/site/index.html").resolve().as_uri())
    expect(page.locator(".symsvel-welcome")).to_be_visible()
    expect(page.locator("body")).to_have_attribute("data-md-color-scheme", "default")
    expect(page.locator(".md-header")).to_have_css("background-color", "rgb(33, 25, 43)")
    page.locator(".md-header__option label:visible").first.click()
    expect(page.locator("body")).to_have_attribute("data-md-color-scheme", "slate")
    expect(page.locator(".symsvel-welcome")).to_have_css("background-color", "rgb(40, 32, 51)")
    page.locator(".md-header__option label:visible").first.click()
    expect(page.locator("body")).to_have_attribute("data-md-color-scheme", "default")
    sidebar = page.locator(".md-sidebar--primary")
    expect(sidebar).to_be_visible()
    expect(sidebar.get_by_role("link", name="Accueil", exact=True)).to_have_count(1)
    expect(sidebar.get_by_role("link", name="Guides SymSvel", exact=True)).to_have_count(0)
    expect(page.locator(".md-content a[href='symsvel/index.html']")).to_have_count(0)
    chapter = sidebar.locator(".md-nav__item--nested").filter(has_text="Prise en main")
    link = chapter.get_by_role("link", name="Installer le Back", exact=True)
    toggle = chapter.locator("input.md-nav__toggle").first
    expect(toggle).not_to_be_checked()
    chapter.locator("label.md-nav__link").first.click()
    expect(toggle).to_be_checked()
    expect(link).to_be_visible()
    link.click()
    expect(page.locator("h1")).to_contain_text("Installer le Back")
    expect(page.locator(".admonition.zeko")).to_be_visible()
    # Material's internal CSS classes changed; verify the native copy control
    # and the actual code element it targets instead.
    copy_button = page.locator("button[data-clipboard-target]")
    expect(copy_button).to_have_count(1)
    expect(copy_button).to_be_enabled()
    target = copy_button.get_attribute("data-clipboard-target")
    assert target, "Missing clipboard target"
    expect(page.locator(target)).to_have_text("symsvel doctor")
    expect(sidebar.get_by_role("link", name="Setup local", exact=True)).to_be_visible()
    toc = page.locator(".md-sidebar--secondary")
    expect(toc).to_be_visible()
    expect(toc.get_by_text("Sommaire", exact=True)).to_be_visible()
    toc.get_by_role("link", name="Installation", exact=True).click()
    assert page.url.endswith("#installation"), page.url
    project = sidebar.locator(".md-nav__item--nested").filter(has_text="Documentation du projet")
    project.locator("label.md-nav__link").first.click()
    project.get_by_role("link", name="Mes notes", exact=True).click()
    expect(page.locator("h1")).to_contain_text("Mes notes")
    mobile = browser.new_page(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mobile.goto((Path(sys.argv[1]) / "dev-doc/site/index.html").resolve().as_uri())
    expect(mobile.locator("h1")).to_be_visible()
    expect(mobile.locator(".symsvel-welcome")).to_be_visible()
    mobile.locator('label[for="__drawer"]:visible').first.click()
    expect(mobile.locator(".md-sidebar--primary").get_by_text("Prise en main", exact=True).first).to_be_visible()
    browser.close()
