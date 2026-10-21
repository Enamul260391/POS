const fs = require("fs");
const path = "d:\\Development\\Pos\\frontend\\features\\pos\\components\\PriceSelectionDialog.tsx";
let c = fs.readFileSync(path, "utf8");
c = c.replace("asChild\n              render", "render");
c = c.replace("            />\n          <Button", " />\n          <Button");
fs.writeFileSync(path, c);
console.log("Done");
