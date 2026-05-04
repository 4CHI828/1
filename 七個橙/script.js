const searchInput = document.getElementById("searchInput");
const playlistCards = document.querySelectorAll(".playlist-card");
const songItems = document.querySelectorAll(".playlist-card li");
const searchCount = document.getElementById("searchCount");
const noResult = document.getElementById("noResult");
const toggleTheme = document.getElementById("toggleTheme");
const backToTop = document.getElementById("backToTop");
const randomSongBtn = document.getElementById("randomSong");

function updateSearch() {
  const query = searchInput.value.trim().toLowerCase();
  let visibleCount = 0;

  songItems.forEach((item) => {
    const text = item.textContent.toLowerCase();
    const visible = query === "" || text.includes(query);
    item.style.display = visible ? "grid" : "none";
    if (visible) visibleCount += 1;
  });

  playlistCards.forEach((card) => {
    const totalSongs = card.querySelectorAll("li").length;
    const hiddenSongs = card.querySelectorAll("li[style*='display: none']").length;
    const visibleSongs = totalSongs - hiddenSongs;
    card.style.display = visibleSongs > 0 ? "grid" : "none";
  });

  searchCount.textContent = `共 ${visibleCount} 首歌曲`;
  noResult.style.display = visibleCount === 0 ? "block" : "none";
}

function toggleDarkMode() {
  document.body.classList.toggle("dark");
}

function selectRandomSong() {
  const visibleSongs = Array.from(songItems).filter(item => item.style.display !== "none");
  
  if (visibleSongs.length === 0) {
    alert("沒有符合搜尋條件的歌曲");
    return;
  }
  
  const randomSong = visibleSongs[Math.floor(Math.random() * visibleSongs.length)];
  randomSong.scrollIntoView({ behavior: "smooth", block: "center" });
  
  // 強烈高亮該歌曲 10秒
  randomSong.style.backgroundColor = "rgba(255, 200, 0, 0.4)";
  randomSong.style.boxShadow = "0 0 10px rgba(255, 200, 0, 0.6)";
  randomSong.style.border = "2px solid rgba(255, 200, 0, 0.8)";
  randomSong.style.borderRadius = "4px";
  randomSong.style.padding = "4px";
  
  setTimeout(() => {
    randomSong.style.backgroundColor = "";
    randomSong.style.boxShadow = "";
    randomSong.style.border = "";
    randomSong.style.borderRadius = "";
    randomSong.style.padding = "";
  }, 5000);
}

if (searchInput) {
  searchInput.addEventListener("input", updateSearch);
  updateSearch();
}

if (toggleTheme) {
  toggleTheme.addEventListener("click", toggleDarkMode);
}

if (randomSongBtn) {
  randomSongBtn.addEventListener("click", selectRandomSong);
}

if (backToTop) {
  window.addEventListener("scroll", () => {
    if (window.scrollY > 320) {
      backToTop.classList.add("visible");
    } else {
      backToTop.classList.remove("visible");
    }
  });

  backToTop.addEventListener("click", () => {
    window.scrollTo({ top: 0, behavior: "smooth" });
  });
}
