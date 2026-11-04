from __future__ import annotations

import re
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "Diagrams" / "ER" / "ER.dbml"
OUTPUT = ROOT / "Diagrams" / "ER" / "ER_diagram.png"
WIDTH, HEIGHT = 3840, 2160

BG = "#F3F6FB"
NAVY = "#10243E"
MUTED = "#53657A"
WHITE = "#FFFFFF"
LINE = "#D8E1EC"
PK_COLOR = "#D97706"
FK_COLOR = "#087E8B"
FONT_REG = r"C:\Windows\Fonts\segoeui.ttf"
FONT_BOLD = r"C:\Windows\Fonts\segoeuib.ttf"

GROUPS = [
    ("IDENTITY & ACCESS", "#2563EB", [
        ("roles", [("PK", "role_id"), ("", "role_name")]),
        ("user_license", [("PK", "license_id"), ("", "license_number"), ("", "license_name"), ("", "license_last_valid_date"), ("", "status")]),
        ("users", [("PK", "user_id"), ("FK", "license_id"), ("FK", "role_id, business_role_id, current_role_id"), ("", "user_name, email"), ("", "status, had_point")]),
        ("sessions", [("PK", "session_id"), ("FK", "user_id"), ("", "ip_address, user_agent"), ("", "is_active, expires_at")]),
    ]),
    ("COMPANIES & STATIONS", "#7C3AED", [
        ("headquarter", [("PK", "headquarter_id"), ("FK", "headquarters_owner_id"), ("", "company_name"), ("", "headquarter_email"), ("", "expire_at")]),
        ("branches", [("PK", "branch_id"), ("FK", "manager_user_id"), ("", "branch_code, branch_name"), ("", "city, township"), ("", "opening_time, closing_time, status")]),
        ("staff", [("PK", "staff_id"), ("FK", "user_id"), ("FK", "branch_id"), ("", "staff_code, full_name"), ("", "is_active")]),
        ("station_business_scope", [("PK / FK", "station_id"), ("FK", "headquarter_id"), ("FK", "branch_manager_user_id"), ("", "created_at, updated_at")]),
    ]),
    ("VEHICLES & INVENTORY", "#059669", [
        ("car_license", [("PK", "car_license_id"), ("", "car_plate_number"), ("", "car_owner_name"), ("", "vehicle_type, model_year"), ("", "filled_gas")]),
        ("user_car", [("PK", "user_car_id"), ("FK", "user_id"), ("FK", "car_id"), ("", "is_active")]),
        ("oil_types", [("PK", "oil_type_id"), ("", "oil_name")]),
        ("tanks", [("PK", "tank_id"), ("FK", "branch_id, oil_type_id"), ("", "capacity_l, current_l"), ("", "alert_pct, is_enabled")]),
        ("station_oil_inventory", [("PK", "inventory_id"), ("FK", "station_id, oil_type_id"), ("", "price_per_litre"), ("", "current_amount_liters, is_active")]),
    ]),
    ("BOOKINGS & FILLS", "#D97706", [
        ("booking", [("PK", "booking_id"), ("FK", "user_id, car_license_id"), ("FK", "station_id, oil_type_id"), ("", "requested_liters, points_to_redeem"), ("", "qr_refrence_code, status"), ("", "expire_at")]),
        ("booking_partial_offers", [("PK / FK", "booking_id"), ("FK", "offered_by"), ("", "offered_liters, status"), ("", "offered_at, answered_at")]),
        ("usage_history", [("PK", "usage_id"), ("FK", "booking_id"), ("FK", "user_id, car_license_id"), ("FK", "station_id, oil_type_id, staff_user_id"), ("", "filled_liters, total_cost"), ("", "points_earned, points_redeemed, discount_mmk")]),
    ]),
    ("COMMUNICATION & ADMIN", "#DB2777", [
        ("feedback", [("PK", "feedback_id"), ("FK", "user_id"), ("", "feedback, created_at")]),
        ("feedback_replies", [("PK", "reply_id"), ("FK", "feedback_id, staff_id"), ("", "reply_text, created_at")]),
        ("system_requests", [("PK", "request_id"), ("FK", "submitter_user_id, role_id, headquarter_id"), ("", "reference_code, request_type"), ("", "status, reviewed_by"), ("", "created_at, reviewed_at")]),
        ("notifications", [("PK", "notification_id"), ("FK", "request_id, recipient_user_id"), ("", "notification_type, subject"), ("", "delivery_status, created_at")]),
        ("log", [("PK", "log_id"), ("FK", "user_id, role_id"), ("", "action, created_at")]),
    ]),
]


def load_font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont:
    return ImageFont.truetype(FONT_BOLD if bold else FONT_REG, size)


def rounded(draw: ImageDraw.ImageDraw, box, radius, fill, outline=None, width=1):
    draw.rounded_rectangle(box, radius=radius, fill=fill, outline=outline, width=width)


def main() -> None:
    source_text = SOURCE.read_text(encoding="utf-8")
    refs = re.findall(r"^Ref:\s+(\w+)\.(\w+)\s*>\s*(\w+)\.(\w+)", source_text, re.M)
    image = Image.new("RGB", (WIDTH, HEIGHT), BG)
    draw = ImageDraw.Draw(image)

    f_title = load_font(66, True)
    f_subtitle = load_font(28)
    f_group = load_font(24, True)
    f_table = load_font(27, True)
    f_field = load_font(21)
    f_tag = load_font(17, True)
    f_panel_title = load_font(25, True)
    f_ref = load_font(18)
    f_footer = load_font(17)

    draw.text((90, 58), "MAP & PUMP", font=load_font(23, True), fill="#087E8B")
    draw.text((90, 93), "Entity Relationship Diagram", font=f_title, fill=NAVY)
    draw.text((94, 181), "Core data model  •  Key columns and all foreign-key links from ER.dbml", font=f_subtitle, fill=MUTED)
    rounded(draw, (WIDTH - 520, 82, WIDTH - 90, 160), 26, "#E3F3F4")
    table_count = sum(len(tables) for _, _, tables in GROUPS)
    draw.text((WIDTH - 480, 105), f"{table_count} tables   |   {len(refs)} relationships", font=load_font(21, True), fill="#087E8B")

    margin = 78
    gap = 34
    col_w = (WIDTH - margin * 2 - gap * (len(GROUPS) - 1)) // len(GROUPS)
    top = 278
    group_y = top
    card_y0 = top + 58
    card_gap = 17
    header_h = 61
    row_h = 35
    card_pad_bottom = 17

    table_positions = {}
    for group_index, (group_name, accent, tables) in enumerate(GROUPS):
        x = margin + group_index * (col_w + gap)
        rounded(draw, (x, group_y, x + col_w, group_y + 48), 14, accent)
        draw.text((x + 18, group_y + 10), group_name, font=f_group, fill=WHITE)
        y = card_y0
        for table_name, fields in tables:
            card_h = header_h + len(fields) * row_h + card_pad_bottom
            rounded(draw, (x, y, x + col_w, y + card_h), 18, WHITE, LINE, 2)
            rounded(draw, (x, y, x + col_w, y + header_h), 17, "#EAF0F8")
            draw.rectangle((x, y + header_h - 16, x + col_w, y + header_h), fill="#EAF0F8")
            draw.rectangle((x, y, x + 8, y + card_h), fill=accent)
            draw.text((x + 24, y + 13), table_name, font=f_table, fill=NAVY)
            for i, (kind, label) in enumerate(fields):
                row_y = y + header_h + i * row_h
                if i % 2 == 1:
                    draw.rectangle((x + 10, row_y, x + col_w - 2, row_y + row_h), fill="#FAFBFD")
                if kind:
                    tag_fill = PK_COLOR if "PK" in kind else FK_COLOR
                    tag_w = 76 if kind == "PK / FK" else 43
                    rounded(draw, (x + 22, row_y + 5, x + 22 + tag_w, row_y + 30), 8, tag_fill)
                    draw.text((x + 29, row_y + 7), kind, font=f_tag, fill=WHITE)
                    text_x = x + 22 + tag_w + 14
                else:
                    text_x = x + 27
                draw.text((text_x, row_y + 4), label, font=f_field, fill=NAVY if kind else MUTED)
            table_positions[table_name] = (x, y, col_w, card_h)
            y += card_h + card_gap

    # Compact foreign-key map makes every relation explicit without crossing wires
    # through the dense table cards.
    panel_top = 1510
    panel_left = margin
    panel_right = WIDTH - margin
    panel_bottom = panel_top + 84 + ((len(refs) + 3) // 4) * 29 + 32
    rounded(draw, (panel_left, panel_top, panel_right, panel_bottom), 20, WHITE, LINE, 2)
    draw.text((panel_left + 28, panel_top + 20), "FOREIGN-KEY MAP", font=f_panel_title, fill=NAVY)
    draw.text((panel_left + 275, panel_top + 23), "child column  →  referenced parent key", font=load_font(19), fill=MUTED)
    draw.line((panel_left + 25, panel_top + 66, panel_right - 25, panel_top + 66), fill=LINE, width=2)
    ref_cols = 4
    inner_x = panel_left + 30
    inner_w = panel_right - panel_left - 60
    ref_col_gap = 35
    ref_col_w = (inner_w - ref_col_gap * (ref_cols - 1)) // ref_cols
    rows_per_col = (len(refs) + ref_cols - 1) // ref_cols
    ref_start_y = panel_top + 84
    ref_line_h = 29
    for i, (src_table, src_col, dst_table, dst_col) in enumerate(refs):
        col = i // rows_per_col
        row = i % rows_per_col
        text = f"{src_table}.{src_col}  →  {dst_table}.{dst_col}"
        xx = inner_x + col * (ref_col_w + ref_col_gap)
        yy = ref_start_y + row * ref_line_h
        draw.text((xx, yy), text, font=f_ref, fill="#334A62")

    footer = "PK = primary key     FK = foreign key     Selected columns shown; see ER.dbml for full column lists."
    draw.text((margin, HEIGHT - 59), footer, font=f_footer, fill=MUTED)

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    image.save(OUTPUT, format="PNG", optimize=True, dpi=(220, 220))
    print(f"Generated {OUTPUT} ({WIDTH}x{HEIGHT}; {len(table_positions)} tables; {len(refs)} links)")


if __name__ == "__main__":
    main()
