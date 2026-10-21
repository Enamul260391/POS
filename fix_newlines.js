const fs = require("fs");
["types/priceOptions.ts", "features/pos/services/priceOptionsService.ts"].forEach(f => {
  const path = "d:\\Development\\Pos\\frontend\\" + f;
  let c = fs.readFileSync(path, "utf8");
  const before = c.length;
  c = c.split("\\n").join("\n");
  fs.writeFileSync(path, c);
  console.log(f + ": " + before + " -> " + c.length + " chars");
});
