import fs from "node:fs/promises";
import path from "node:path";
import { SpreadsheetFile, Workbook } from "@oai/artifact-tool";

const root = "D:/PROJECT/Map_And_Pump";
const extractedPath = path.join(root, "tmp/pdf_to_excel/extracted.json");
const outputDir = path.join(root, "outputs/pdf_to_excel");
const outputPath = path.join(outputDir, "company_research_practice.xlsx");
const previewPath = path.join(outputDir, "company_research_practice_preview.png");

const extracted = JSON.parse(await fs.readFile(extractedPath, "utf8"));

const workbook = Workbook.create();
const sheet = workbook.worksheets.add("企業研究");
const notes = workbook.worksheets.add("Source Notes");

sheet.showGridLines = false;
notes.showGridLines = false;

sheet.getRange("A1:D1").merge();
sheet.getRange("A1").values = [["企業研究の練習"]];
sheet.getRange("A2:D2").merge();
sheet.getRange("A2").values = [["課題：日本企業を一つ選んで、できるだけくわしく調べる。"]];
sheet.getRange("A3:D3").merge();
sheet.getRange("A3").values = [["IT 企業でも、IT 企業ではない会社でもどちらでも可。"]];

sheet.getRange("A5:D5").merge();
sheet.getRange("A5").values = [["企業研究とは"]];
sheet.getRange("A6:D6").merge();
sheet.getRange("A6").values = [["就職活動のために興味のある会社について調べる（研究する）こと。"]];

const section1 = [
  ["きぎょうめい\n企業名", ""],
  ["しょざいち\n所在地", ""],
  ["せつりつねん\n設立年\n（いつできたか）", ""],
  ["しほんきん\n資本金", "円"],
  ["しゃちょうめい\n社長名", ""],
  ["じゅうぎょういんすう\n従業員数", "人"],
  ["じぎょうないよう\n事業内容\nしごとないよう\n（仕事内容）\nビジネスモデル\n（誰を相手にするビジネスか）", ""],
];

const section2 = [
  ["しょにんきゅう\n初任給\n（最初の給料の額）", "円"],
  ["きんむにちじ\n勤務日時", ""],
  ["しょうよ うむ\n賞与の有無\n（ある場合は金額）", "円"],
  ["けんしゅうせいど\n研修制度\nけんしゅう\n（どんな研修か）", ""],
];

sheet.getRange("A8:D8").merge();
sheet.getRange("A8").values = [["会社について調べること"]];
sheet.getRange("A9:D9").values = [["項目", "記入欄", "単位", "メモ"]];

let row = 10;
for (const [label, unit] of section1) {
  sheet.getRange(`A${row}:D${row}`).values = [[label, "", unit || "", ""]];
  row += 1;
}

row += 1;
sheet.getRange(`A${row}:D${row}`).merge();
sheet.getRange(`A${row}`).values = [["面接を受けるため、働くために調べたほうがいいこと"]];
row += 1;
sheet.getRange(`A${row}:D${row}`).values = [["項目", "記入欄", "単位", "メモ"]];
row += 1;

for (const [label, unit] of section2) {
  sheet.getRange(`A${row}:D${row}`).values = [[label, "", unit || "", ""]];
  row += 1;
}

row += 1;
sheet.getRange(`A${row}:D${row}`).merge();
sheet.getRange(`A${row}`).values = [["その他に web サイトを調べてわかったこと"]];
row += 1;
sheet.getRange(`A${row}:D${row + 3}`).merge();
sheet.getRange(`A${row}`).values = [["その会社の長所や特徴。他の会社にはない、その会社だけのポイント"]];

notes.getRange("A1:D1").merge();
notes.getRange("A1").values = [["PDF Source Notes"]];
notes.getRange("A3:D3").values = [["Source File", extracted.source, "", ""]];
notes.getRange("A5:D5").values = [["Page", "Extracted Text", "Detected Tables", "Notes"]];

const noteRows = extracted.pages.map((page) => [
  page.page,
  page.text,
  page.tables.length,
  "PDF content preserved as source material only.",
]);
notes.getRangeByIndexes(5, 0, noteRows.length, 4).values = noteRows;

const titleStyle = {
  fill: "#1F4E79",
  font: { bold: true, color: "#FFFFFF", size: 16 },
  horizontalAlignment: "center",
  verticalAlignment: "center",
};
const subtitleStyle = {
  fill: "#D9EAF7",
  font: { bold: true, color: "#1F2937" },
};
const headerStyle = {
  fill: "#5B9BD5",
  font: { bold: true, color: "#FFFFFF" },
  horizontalAlignment: "center",
  verticalAlignment: "center",
};
const labelStyle = {
  fill: "#EAF3F8",
  font: { bold: true, color: "#1F2937" },
  verticalAlignment: "center",
  wrapText: true,
};
const inputStyle = {
  fill: "#FFFFFF",
  verticalAlignment: "top",
  wrapText: true,
};

sheet.getRange("A1:D1").format = titleStyle;
sheet.getRange("A2:D3").format = { fill: "#F4F9FC", font: { color: "#1F2937" }, wrapText: true };
sheet.getRange("A5:D5").format = subtitleStyle;
sheet.getRange("A6:D6").format = { fill: "#FFFFFF", wrapText: true };
sheet.getRange("A8:D8").format = subtitleStyle;
sheet.getRange("A9:D9").format = headerStyle;
sheet.getRange("A10:A16").format = labelStyle;
sheet.getRange("B10:D16").format = inputStyle;
sheet.getRange("A18:D18").format = subtitleStyle;
sheet.getRange("A19:D19").format = headerStyle;
sheet.getRange("A20:A23").format = labelStyle;
sheet.getRange("B20:D23").format = inputStyle;
sheet.getRange("A25:D25").format = subtitleStyle;
sheet.getRange("A26:D29").format = inputStyle;

sheet.getRange("A8:D29").format.borders = { preset: "all", style: "thin", color: "#C9D6DF" };
sheet.getRange("A5:D6").format.borders = { preset: "outside", style: "thin", color: "#C9D6DF" };

sheet.getRange("A:A").format.columnWidth = 28;
sheet.getRange("B:B").format.columnWidth = 42;
sheet.getRange("C:C").format.columnWidth = 10;
sheet.getRange("D:D").format.columnWidth = 28;
sheet.getRange("A1:D1").format.rowHeight = 28;
sheet.getRange("A2:D3").format.rowHeight = 24;
sheet.getRange("A10:D16").format.rowHeight = 48;
sheet.getRange("A16:D16").format.rowHeight = 92;
sheet.getRange("A20:D23").format.rowHeight = 52;
sheet.getRange("A26:D29").format.rowHeight = 92;
sheet.freezePanes.freezeRows(9);

notes.getRange("A1:D1").format = titleStyle;
notes.getRange("A5:D5").format = headerStyle;
notes.getRange("A6:D7").format = { wrapText: true, verticalAlignment: "top" };
notes.getRange("A:D").format.borders = { preset: "inside", style: "thin", color: "#E5E7EB" };
notes.getRange("A:A").format.columnWidth = 10;
notes.getRange("B:B").format.columnWidth = 80;
notes.getRange("C:C").format.columnWidth = 16;
notes.getRange("D:D").format.columnWidth = 38;
notes.getRange("A6:D7").format.rowHeight = 150;
notes.freezePanes.freezeRows(5);

const preview = await workbook.render({
  sheetName: "企業研究",
  autoCrop: "all",
  scale: 1,
  format: "png",
});
await fs.mkdir(outputDir, { recursive: true });
await fs.writeFile(previewPath, new Uint8Array(await preview.arrayBuffer()));

const summary = await workbook.inspect({
  kind: "workbook,sheet,table",
  maxChars: 4000,
  tableMaxRows: 12,
  tableMaxCols: 6,
});
console.log(summary.ndjson);

const errors = await workbook.inspect({
  kind: "match",
  searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A",
  options: { useRegex: true, maxResults: 100 },
  summary: "formula error scan",
});
console.log(errors.ndjson);

const output = await SpreadsheetFile.exportXlsx(workbook);
await output.save(outputPath);
console.log(JSON.stringify({ outputPath, previewPath }));
